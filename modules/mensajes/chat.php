<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'tecnico', 'recepcionista', 'consultor']);

$usuario_id = $_SESSION['usuario_id'];

// ============================================================
// AJAX: Enviar mensaje (texto + imagen)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    $destinatario = (int)$_POST['destinatario_id'];
    $contenido = trim($_POST['contenido'] ?? '');
    $imagen_url = '';

    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $errImg = '';
        $imagen_url = procesarImagenSubida($_FILES['imagen'], 'chat', 'chat', $errImg, 1280, 8) ?? '';
        if ($imagen_url === '' && empty($contenido)) {
            echo json_encode(['error' => $errImg ?: 'No se pudo procesar la imagen']);
            exit;
        }
    }

    if (empty($contenido) && empty($imagen_url)) {
        echo json_encode(['error' => 'Mensaje vacío']);
        exit;
    }

    $stmt = $conexion->prepare("INSERT INTO mensajes (remitente_id, destinatario_id, contenido, imagen_url) VALUES (?, ?, ?, ?)");
    $stmt->execute([$usuario_id, $destinatario, $contenido, $imagen_url]);
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================
// AJAX: Marcar mensajes como leídos
// ============================================================
if (isset($_GET['marcar_leidos']) && isset($_GET['con_id'])) {
    header('Content-Type: application/json');
    $con_id = (int)$_GET['con_id'];
    $stmt = $conexion->prepare("UPDATE mensajes SET leido = 1, fecha_leido = NOW() WHERE remitente_id = ? AND destinatario_id = ? AND leido = 0");
    $stmt->execute([$con_id, $usuario_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================
// AJAX: Cargar mensajes nuevos (incremental)
// ============================================================
if (isset($_GET['cargar_mensajes']) && isset($_GET['con_id'])) {
    header('Content-Type: application/json');
    $con_id = (int)$_GET['con_id'];
    $ultimo_id = isset($_GET['ultimo_id']) ? (int)$_GET['ultimo_id'] : 0;
    
    $sql = "SELECT m.*, u.nombre as remitente_nombre, u.foto as remitente_foto
            FROM mensajes m
            JOIN usuarios u ON m.remitente_id = u.id_usuario
            WHERE ((remitente_id = ? AND destinatario_id = ? AND eliminado_para_remitente = 0)
                OR (remitente_id = ? AND destinatario_id = ? AND eliminado_para_destinatario = 0))
                AND m.id_mensaje > ?
            ORDER BY m.fecha_envio ASC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$usuario_id, $con_id, $con_id, $usuario_id, $ultimo_id]);
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($mensajes);
    exit;
}

// ============================================================
// AJAX: Eliminar conversación unilateralmente
// ============================================================
if (isset($_POST['eliminar_conversacion']) && isset($_POST['con_id'])) {
    header('Content-Type: application/json');
    $con_id = (int)$_POST['con_id'];
    try {
        $sql = "UPDATE mensajes SET 
                eliminado_para_remitente = IF(remitente_id = ?, 1, eliminado_para_remitente),
                eliminado_para_destinatario = IF(destinatario_id = ?, 1, eliminado_para_destinatario)
                WHERE (remitente_id = ? AND destinatario_id = ?) OR (remitente_id = ? AND destinatario_id = ?)";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([$usuario_id, $usuario_id, $usuario_id, $con_id, $con_id, $usuario_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => errorInterno($e)]);
    }
    exit;
}

// ============================================================
// AJAX: Listar chats (conversaciones activas)
// ============================================================
if (isset($_GET['listar_chats'])) {
    header('Content-Type: application/json');
    $sql = "SELECT 
                u.id_usuario, u.nombre, u.rol, u.foto, u.ultima_actividad,
                (SELECT COUNT(*) FROM mensajes WHERE destinatario_id = ? AND remitente_id = u.id_usuario AND leido = 0) as no_leidos,
                (SELECT 
                    CASE 
                        WHEN imagen_url IS NOT NULL AND imagen_url != '' AND (contenido IS NULL OR contenido = '') THEN '📷 Imagen'
                        WHEN imagen_url IS NOT NULL AND imagen_url != '' AND contenido IS NOT NULL AND contenido != '' THEN CONCAT(contenido, ' 📷')
                        ELSE contenido
                    END
                 FROM mensajes 
                 WHERE ((remitente_id = ? AND destinatario_id = u.id_usuario AND eliminado_para_remitente = 0)
                     OR (remitente_id = u.id_usuario AND destinatario_id = ? AND eliminado_para_destinatario = 0))
                 ORDER BY fecha_envio DESC LIMIT 1) as ultimo_mensaje,
                (SELECT fecha_envio FROM mensajes 
                 WHERE ((remitente_id = ? AND destinatario_id = u.id_usuario AND eliminado_para_remitente = 0)
                     OR (remitente_id = u.id_usuario AND destinatario_id = ? AND eliminado_para_destinatario = 0))
                 ORDER BY fecha_envio DESC LIMIT 1) as ultima_fecha
            FROM usuarios u
            WHERE u.id_usuario != ? AND u.estado = 'activo'
            AND EXISTS (
                SELECT 1 FROM mensajes 
                WHERE ((remitente_id = ? AND destinatario_id = u.id_usuario AND eliminado_para_remitente = 0)
                    OR (remitente_id = u.id_usuario AND destinatario_id = ? AND eliminado_para_destinatario = 0))
            )
            ORDER BY ultima_fecha DESC";
    $params = [$usuario_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id];
    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($chats as &$c) {
        if (empty($c['ultimo_mensaje'])) $c['ultimo_mensaje'] = 'Sin mensajes';
        // Estado online: última actividad en los últimos 2 minutos
        $c['online'] = ($c['ultima_actividad'] && (time() - strtotime($c['ultima_actividad'])) < 120);
        $c['foto'] = $c['foto'] ?? '';
    }
    echo json_encode($chats);
    exit;
}

// ============================================================
// AJAX: Listar todos los contactos (todos los usuarios activos)
// ============================================================
if (isset($_GET['listar_contactos'])) {
    header('Content-Type: application/json');
    $buscar = trim($_GET['buscar'] ?? '');
    
    $sql = "SELECT u.id_usuario, u.nombre, u.rol, u.foto, u.ultima_actividad,
                   (SELECT COUNT(*) FROM mensajes WHERE destinatario_id = ? AND remitente_id = u.id_usuario AND leido = 0) as no_leidos,
                   (SELECT 
                       CASE 
                           WHEN imagen_url IS NOT NULL AND imagen_url != '' AND (contenido IS NULL OR contenido = '') THEN '📷 Imagen'
                           WHEN imagen_url IS NOT NULL AND imagen_url != '' AND contenido IS NOT NULL AND contenido != '' THEN CONCAT(contenido, ' 📷')
                           ELSE contenido
                       END
                    FROM mensajes 
                    WHERE ((remitente_id = ? AND destinatario_id = u.id_usuario AND eliminado_para_remitente = 0)
                        OR (remitente_id = u.id_usuario AND destinatario_id = ? AND eliminado_para_destinatario = 0))
                    ORDER BY fecha_envio DESC LIMIT 1) as ultimo_mensaje,
                   (SELECT fecha_envio FROM mensajes 
                    WHERE ((remitente_id = ? AND destinatario_id = u.id_usuario AND eliminado_para_remitente = 0)
                        OR (remitente_id = u.id_usuario AND destinatario_id = ? AND eliminado_para_destinatario = 0))
                    ORDER BY fecha_envio DESC LIMIT 1) as ultima_fecha
            FROM usuarios u
            WHERE u.id_usuario != ? AND u.estado = 'activo'";
    $params = [$usuario_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id];
    
    if (!empty($buscar)) {
        $sql .= " AND (u.nombre LIKE ? OR u.rol LIKE ? OR u.email LIKE ?)";
        $like = "%$buscar%";
        $params = array_merge($params, [$like, $like, $like]);
    }
    $sql .= " ORDER BY u.nombre ASC";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($usuarios as &$u) {
        if (empty($u['ultimo_mensaje'])) $u['ultimo_mensaje'] = 'Sin conversación previa';
        $u['online'] = ($u['ultima_actividad'] && (time() - strtotime($u['ultima_actividad'])) < 120);
        $u['foto'] = $u['foto'] ?? '';
    }
    echo json_encode($usuarios);
    exit;
}

// ============================================================
// AJAX: Heartbeat (actualizar última actividad)
// ============================================================
if (isset($_GET['heartbeat'])) {
    $conexion->prepare("UPDATE usuarios SET ultima_actividad = NOW() WHERE id_usuario = ?")->execute([$usuario_id]);
    exit;
}

// ============================================================
// AJAX: Contar no leídos (badge)
// ============================================================
if (isset($_GET['contar_no_leidos'])) {
    $stmt = $conexion->prepare("SELECT COUNT(*) FROM mensajes WHERE destinatario_id = ? AND leido = 0");
    $stmt->execute([$usuario_id]);
    echo $stmt->fetchColumn();
    exit;
}

// ============================================================
// INTERFAZ PRINCIPAL
// ============================================================
include __DIR__ . '/../../includes/header.php';
?>

<style>
    :root, [data-bs-theme="light"] {
        --chat-bg: #f8fafc;
        --chat-sidebar: #ffffff;
        --chat-border: #e2e8f0;
        --chat-bubble-own: #0284c7;
        --chat-bubble-other: #e2e8f0;
        --chat-text: #0f172a;
        --chat-bubble-other-text: #1e293b;
        --chat-input-bg: #ffffff;
        --chat-hover: rgba(2, 132, 199, 0.1);
        --chat-active: rgba(2, 132, 199, 0.18);
    }
    [data-bs-theme="dark"] {
        --chat-bg: #0f1219;
        --chat-sidebar: #1a1e29;
        --chat-border: #2b3040;
        --chat-bubble-own: #2f7bff;
        --chat-bubble-other: #2d3748;
        --chat-text: #f0f2f5;
        --chat-bubble-other-text: #ffffff;
        --chat-input-bg: #0f1219;
        --chat-hover: rgba(47, 123, 255, 0.2);
        --chat-active: rgba(47, 123, 255, 0.3);
    }
    .chat-container {
        display: flex;
        height: calc(100vh - 140px);
        background: var(--chat-bg);
        border: 1px solid var(--chat-border);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    .chat-sidebar {
        width: 340px;
        background: var(--chat-sidebar);
        border-right: 1px solid var(--chat-border);
        display: flex;
        flex-direction: column;
    }
    .sidebar-tabs {
        display: flex;
        border-bottom: 1px solid var(--chat-border);
    }
    .tab-btn {
        flex: 1;
        background: transparent;
        border: none;
        padding: 12px;
        color: var(--text-secondary, #8a92a4);
        cursor: pointer;
        font-weight: bold;
        transition: all 0.2s ease;
    }
    .tab-btn.active {
        color: var(--accent-cyan, #2f7bff);
        border-bottom: 2px solid var(--accent-cyan, #2f7bff);
    }
    .search-box {
        padding: 15px;
        border-bottom: 1px solid var(--chat-border);
    }
    .search-box input {
        background: var(--chat-input-bg);
        border: 1px solid var(--chat-border);
        border-radius: 30px;
        padding: 8px 15px;
        color: var(--chat-text);
        width: 100%;
    }
    .contact-list {
        flex: 1;
        overflow-y: auto;
    }
    .contact-item {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        cursor: pointer;
        border-bottom: 1px solid var(--chat-border);
        position: relative;
        transition: all 0.15s ease;
        color: var(--chat-text);
    }
    .contact-item:hover {
        background: var(--chat-hover);
        transform: translateX(3px);
    }
    .contact-item.active {
        background: var(--chat-active);
        border-left: 3px solid var(--accent-cyan, #2f7bff);
    }
    .contact-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 12px;
        background: var(--accent-cyan, #2f7bff);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        text-transform: uppercase;
        flex-shrink: 0;
    }
    .contact-info {
        flex: 1;
        overflow: hidden;
    }
    .contact-name {
        font-weight: bold;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px;
        color: var(--chat-text);
    }
    .contact-role { font-size: 11px; opacity: 0.75; }
    .contact-lastmsg { font-size: 12px; opacity: 0.85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    
    .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        background: #6b7280;
        box-shadow: 0 0 2px rgba(0,0,0,0.5);
        flex-shrink: 0;
    }
    .status-dot.online {
        background: #22c55e;
    }
    .status-dot.offline {
        background: #ef4444;
    }
    
    .unread-badge {
        background: #ef4444;
        color: white;
        border-radius: 20px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: bold;
        margin-left: 8px;
    }
    .delete-chat {
        background: transparent;
        border: none;
        color: #ef4444;
        cursor: pointer;
        margin-left: 8px;
        font-size: 14px;
        opacity: 0.6;
    }
    .delete-chat:hover { opacity: 1; transform: scale(1.1); }
    .chat-main {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: var(--chat-bg);
    }
    .chat-header {
        padding: 15px 20px;
        border-bottom: 1px solid var(--chat-border);
        background: var(--chat-sidebar);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .chat-messages {
        flex: 1;
        overflow-y: auto;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .message {
        display: flex;
        max-width: 70%;
        animation: fadeInUp 0.2s ease;
    }
    .message.own { align-self: flex-end; justify-content: flex-end; }
    .message-bubble {
        padding: 10px 14px;
        border-radius: 18px;
        word-break: break-word;
    }
    .message.own .message-bubble {
        background: var(--chat-bubble-own);
        color: #ffffff;
        border-bottom-right-radius: 4px;
    }
    .message.other .message-bubble {
        background: var(--chat-bubble-other);
        color: var(--chat-bubble-other-text);
        border-bottom-left-radius: 4px;
    }
    .message-img {
        max-width: 200px;
        max-height: 200px;
        border-radius: 12px;
        cursor: pointer;
        margin-top: 5px;
    }
    .message-time {
        font-size: 10px;
        opacity: 0.7;
        margin-top: 4px;
        display: flex;
        justify-content: flex-end;
        gap: 6px;
    }
    .seen-status { font-size: 10px; margin-left: 6px; }
    .chat-input {
        padding: 15px;
        background: var(--chat-sidebar);
        border-top: 1px solid var(--chat-border);
        display: flex;
        gap: 10px;
        align-items: center;
    }
    .chat-input textarea {
        flex: 1;
        background: var(--chat-input-bg);
        border: 1px solid var(--chat-border);
        border-radius: 24px;
        padding: 10px 15px;
        color: var(--chat-text);
        resize: none;
        font-family: inherit;
    }
    .chat-input button {
        background: var(--accent-cyan, #2f7bff);
        border: none;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        color: white;
        cursor: pointer;
    }
    .file-label {
        background: var(--chat-border);
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: var(--chat-text);
    }
    .image-preview {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 5px 15px 0 15px;
        background: var(--chat-sidebar);
        padding: 5px 10px;
        border-radius: 20px;
        border: 1px solid var(--chat-border);
    }
    .image-preview img {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 8px;
    }
    .image-preview button {
        background: none;
        border: none;
        color: #ef4444;
        cursor: pointer;
        font-size: 14px;
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 768px) {
        .chat-sidebar {
            position: absolute;
            left: -340px;
            z-index: 10;
            height: calc(100vh - 140px);
        }
        .chat-sidebar.active { left: 0; }
        .chat-main { width: 100%; }
        .message { max-width: 85%; }
    }
    .no-chat-selected {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: var(--text-secondary, #8a92a4);
        text-align: center;
        flex-direction: column;
    }
</style>

<div class="container-fluid animate__animated animate__fadeIn p-0">
<div class="chat-container">
    <div class="chat-sidebar" id="sidebarContactos">
        <div class="sidebar-tabs">
            <button class="tab-btn active" data-tab="chats">💬 Chats</button>
            <button class="tab-btn" data-tab="contactos">👥 Contactos</button>
        </div>
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="🔍 Buscar...">
        </div>
        <div class="contact-list" id="contactList">
            <div class="text-center p-4 text-muted">Cargando...</div>
        </div>
    </div>
    <div class="chat-main">
        <div class="chat-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary rounded-pill d-md-none">
                    <i class="fas fa-bars"></i>
                </button>
                <div id="chatHeaderInfo">
                    <span class="text-muted">Selecciona un chat</span>
                </div>
            </div>
            <button id="deleteChatBtn" class="btn btn-sm btn-outline-danger rounded-pill" style="display: none;">
                <i class="fas fa-trash-alt me-1"></i> Borrar chat
            </button>
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="no-chat-selected">
                <i class="fas fa-comments fa-3x mb-2 d-block"></i>
                <span>Selecciona una conversación para empezar</span>
            </div>
        </div>
        <div class="chat-input" id="chatInputArea" style="display: none;">
            <label class="file-label" id="imageLabel">
                <i class="fas fa-image"></i>
                <input type="file" id="imageInput" accept="image/*" style="display: none;">
            </label>
            <textarea id="messageText" rows="1" placeholder="Escribe un mensaje..."></textarea>
            <button id="sendBtn"><i class="fas fa-paper-plane"></i></button>
        </div>
        <div id="imagePreviewArea" style="display: none;" class="image-preview"></div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
let currentContactId = null;
let userId = <?= (int)$usuario_id ?>;
let activeTab = 'chats';
let searchTerm = '';
let lastMessageId = 0;
let pollingInterval = null;
let listRefreshInterval = null;
let isSending = false;
let currentImageFile = null;

// Heartbeat cada 15 segundos
function heartbeat() { 
    $.get("chat.php?heartbeat=1"); 
}
setInterval(heartbeat, 15000);
heartbeat();

// Cargar lista (chats o contactos) con estado online
function loadList() {
    let endpoint = (activeTab === 'chats') ? 'listar_chats' : 'listar_contactos';
    $.get(`chat.php?${endpoint}=1&buscar=${encodeURIComponent(searchTerm)}`, function(data) {
        let html = "";
        if (data.error) {
            html = `<div class="text-center p-4 text-danger">Error: ${data.error}</div>`;
        } else if (data.length === 0) {
            html = `<div class="text-center p-4 text-muted">${activeTab === 'chats' ? 'No hay conversaciones' : 'No hay usuarios activos'}</div>`;
        } else {
            data.forEach(u => {
                let foto = u.foto ? "../../" + u.foto : null;
                let avatarHtml = foto ? `<img src="${foto}" class="contact-avatar" onerror="this.src=''">` :
                                 `<div class="contact-avatar">${u.nombre.charAt(0).toUpperCase()}</div>`;
                let lastMsg = (u.ultimo_mensaje || "").length > 50 ? (u.ultimo_mensaje.substring(0,50)+"...") : (u.ultimo_mensaje || "Sin mensajes");
                let badge = u.no_leidos > 0 ? `<span class="unread-badge">${u.no_leidos > 99 ? '99+' : u.no_leidos}</span>` : "";
                let statusClass = u.online ? 'online' : 'offline';
                let statusDot = `<span class="status-dot ${statusClass}" title="${u.online ? 'En línea' : 'Desconectado'}"></span>`;
                html += `
                    <div class="contact-item" data-id="${u.id_usuario}" data-nombre="${escapeHtml(u.nombre)}" data-foto="${foto || ''}">
                        ${avatarHtml}
                        <div class="contact-info">
                            <div class="contact-name">
                                <span>${escapeHtml(u.nombre)}</span> ${statusDot}
                                ${badge}
                            </div>
                            <div class="contact-role">${escapeHtml(u.rol)}</div>
                            <div class="contact-lastmsg">${escapeHtml(lastMsg)}</div>
                        </div>
                        <button class="delete-chat" data-id="${u.id_usuario}" title="Eliminar conversación (solo para ti)"><i class="fas fa-trash-alt"></i></button>
                    </div>
                `;
            });
        }
        $("#contactList").html(html);
        if (currentContactId) {
            $(`.contact-item[data-id="${currentContactId}"]`).addClass("active");
        }
        $(".delete-chat").off("click").on("click", function(e) {
            e.stopPropagation();
            let id = $(this).data("id");
            eliminarConversacion(id);
        });
    }, "json");
}

// Eliminar conversación unilateral
function eliminarConversacion(contactId) {
    if (!confirm("¿Eliminar esta conversación SOLO PARA TI? El otro usuario seguirá viendo sus mensajes.")) return;
    $.post('chat.php', { eliminar_conversacion: 1, con_id: contactId }, function(res) {
        if (res.success) {
            if (currentContactId == contactId) {
                currentContactId = null;
                $("#chatHeaderInfo").html('<span class="text-muted">Selecciona un chat</span>');
                $("#chatMessages").html('<div class="no-chat-selected"><i class="fas fa-comments fa-3x mb-2 d-block"></i><span>Selecciona una conversación para empezar</span></div>');
                $("#chatInputArea").hide();
                $("#deleteChatBtn").hide();
                if (pollingInterval) clearInterval(pollingInterval);
            }
            loadList();
            actualizarBadgeGeneral();
        } else {
            alert("Error al eliminar: " + res.error);
        }
    }, "json");
}

// Cargar mensajes nuevos (incremental)
function loadMessages(contactId, ultimoId = 0) {
    if (!contactId) return;
    $.get(`chat.php?cargar_mensajes=1&con_id=${contactId}&ultimo_id=${ultimoId}`, function(messages) {
        if (!messages || messages.length === 0) return;
        let htmlFragment = "";
        let maxId = ultimoId;
        messages.forEach(msg => {
            let isOwn = (msg.remitente_id == userId);
            let hora = new Date(msg.fecha_envio).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
            let imagenHtml = "";
            if (msg.imagen_url) {
                let imgUrl = "../../" + msg.imagen_url;
                imagenHtml = `<div><img src="${imgUrl}" class="message-img" onclick="window.open(this.src)"></div>`;
            }
            let statusHtml = "";
            if (isOwn) {
                statusHtml = `<span class="seen-status">${msg.leido ? '✓✓ Visto' : '✓ Entregado'}</span>`;
            }
            htmlFragment += `
                <div class="message ${isOwn ? 'own' : 'other'}">
                    <div class="message-bubble">
                        ${msg.contenido ? '<div>'+escapeHtml(msg.contenido)+'</div>' : ''}
                        ${imagenHtml}
                        <div class="message-time">${hora} ${statusHtml}</div>
                    </div>
                </div>
            `;
            if (msg.id_mensaje > maxId) maxId = msg.id_mensaje;
        });
        if (ultimoId === 0) {
            $("#chatMessages").html(htmlFragment);
        } else {
            $("#chatMessages").append(htmlFragment);
        }
        $("#chatMessages").scrollTop($("#chatMessages")[0].scrollHeight);
        if (maxId > lastMessageId) {
            lastMessageId = maxId;
            $.get(`chat.php?marcar_leidos=1&con_id=${contactId}`);
            actualizarBadgeGeneral();
            loadList();
        }
    }, "json");
}

// Enviar mensaje
function sendMessage() {
    if (isSending) return;
    let text = $("#messageText").val().trim();
    let file = currentImageFile;
    if (!text && !file) return;
    isSending = true;
    let formData = new FormData();
    formData.append("destinatario_id", currentContactId);
    formData.append("contenido", text);
    if (file) formData.append("imagen", file);
    $.ajax({
        url: "chat.php",
        type: "POST",
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                $("#messageText").val("");
                currentImageFile = null;
                $("#imagePreviewArea").hide().empty();
                $("#imageInput").val("");
                loadMessages(currentContactId, lastMessageId);
                loadList();
                actualizarBadgeGeneral();
            } else {
                alert("Error al enviar: " + (res.error || "Desconocido"));
            }
        },
        error: function() {
            alert("Error de conexión al enviar mensaje.");
        },
        complete: function() {
            isSending = false;
        }
    });
}

// Previsualización de imagen
$("#imageInput").on("change", function(e) {
    const file = e.target.files[0];
    if (file && file.type.startsWith("image/")) {
        currentImageFile = file;
        const reader = new FileReader();
        reader.onload = function(event) {
            $("#imagePreviewArea").html(`
                <img src="${event.target.result}" alt="Preview">
                <button id="removeImageBtn"><i class="fas fa-times-circle"></i></button>
            `).show();
            $("#removeImageBtn").click(function() {
                currentImageFile = null;
                $("#imageInput").val("");
                $("#imagePreviewArea").hide().empty();
            });
        };
        reader.readAsDataURL(file);
    } else {
        currentImageFile = null;
        $("#imagePreviewArea").hide();
        alert("Selecciona un archivo de imagen válido (jpg, png, gif).");
    }
});

function selectChat(contactId, nombre, foto) {
    if (currentContactId === contactId) return;
    currentContactId = contactId;
    $(".contact-item").removeClass("active");
    $(`.contact-item[data-id="${contactId}"]`).addClass("active");
    let fotoHtml = foto ? `<img src="${foto}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;margin-right:10px;">` :
                   `<div style="width:40px;height:40px;border-radius:50%;background:#2f7bff;display:flex;align-items:center;justify-content:center;margin-right:10px;"><i class="fas fa-user"></i></div>`;
    let contactRole = $(`.contact-item[data-id="${contactId}"] .contact-role`).text();
    $("#chatHeaderInfo").html(`
        <div class="d-flex align-items-center">
            ${fotoHtml}
            <div>
                <strong>${escapeHtml(nombre)}</strong>
                <small class="text-muted d-block">${escapeHtml(contactRole)}</small>
            </div>
        </div>
    `);
    $("#chatInputArea").show();
    $("#deleteChatBtn").show();
    lastMessageId = 0;
    loadMessages(contactId, 0);
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(() => {
        if (currentContactId) loadMessages(currentContactId, lastMessageId);
    }, 3000);
}

function actualizarBadgeGeneral() {
    fetch('chat.php?contar_no_leidos=1')
        .then(res => res.text())
        .then(count => {
            let badgeHeader = window.parent.document.getElementById('mensajesBadge');
            if (badgeHeader) {
                if (count > 0) {
                    badgeHeader.textContent = count > 99 ? '99+' : count;
                    badgeHeader.style.display = 'inline-block';
                } else {
                    badgeHeader.style.display = 'none';
                }
            }
        })
        .catch(err => console.log('Error actualizando badge:', err));
}

$(document).on("click", ".contact-item", function(e) {
    if ($(e.target).closest('.delete-chat').length) return;
    let id = $(this).data("id");
    let nombre = $(this).data("nombre");
    let foto = $(this).data("foto");
    selectChat(id, nombre, foto);
    if (window.innerWidth <= 768) $("#sidebarContactos").removeClass("active");
});

$("#sendBtn").click(sendMessage);
$("#messageText").on("keypress", function(e) {
    if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});
$("#imageLabel").click(() => $("#imageInput").click());
$("#toggleSidebar").click(() => $("#sidebarContactos").toggleClass("active"));
$("#deleteChatBtn").click(() => {
    if (currentContactId) eliminarConversacion(currentContactId);
});

$(".tab-btn").click(function() {
    activeTab = $(this).data("tab");
    $(".tab-btn").removeClass("active");
    $(this).addClass("active");
    loadList();
});
$("#searchInput").on("input", function() {
    searchTerm = $(this).val();
    loadList();
});

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Carga inicial y refresco cada 3 segundos
loadList();
listRefreshInterval = setInterval(() => { 
    loadList(); 
    actualizarBadgeGeneral(); 
}, 3000);
setInterval(actualizarBadgeGeneral, 10000);
</script>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>