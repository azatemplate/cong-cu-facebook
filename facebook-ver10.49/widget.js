(function() {
    // Prevent double loading
    if (window.WebChatWidgetLoaded) return;
    window.WebChatWidgetLoaded = true;

    // Discover script tag & parameters accurately
    var currentScript = document.currentScript;
    if (!currentScript) {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            if (scripts[i].src && scripts[i].src.indexOf('widget.js') !== -1) {
                currentScript = scripts[i];
                break;
            }
        }
    }
    if (!currentScript) {
        var allScripts = document.getElementsByTagName('script');
        currentScript = allScripts[allScripts.length - 1];
    }

    var accountId = currentScript ? (currentScript.getAttribute('data-account') || currentScript.getAttribute('data-account-id') || 1) : 1;
    var serverAttr = currentScript ? (currentScript.getAttribute('data-server') || currentScript.getAttribute('data-server-url') || '') : '';
    
    // Base API URL automatically detected from data-server attribute or script src
    var scriptSrc = (currentScript && currentScript.src) ? currentScript.src : '';
    var apiBaseUrl = serverAttr || (scriptSrc.indexOf('/') !== -1 ? scriptSrc.substring(0, scriptSrc.lastIndexOf('/')) : '');
    if (!apiBaseUrl) apiBaseUrl = window.location.origin;

    // State Variables: Ensure visitorUuid exists immediately on page load!
    var visitorUuid = localStorage.getItem('web_chat_visitor_uuid_' + accountId) || '';
    if (!visitorUuid) {
        visitorUuid = 'web_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
        localStorage.setItem('web_chat_visitor_uuid_' + accountId, visitorUuid);
    }

    var isChatOpen = false;
    var isPolicyAgreed = localStorage.getItem('web_chat_policy_agreed_' + accountId) === '1';
    var widgetConfig = {
        bot_name: 'Gấu cười',
        bot_avatar: 'https://s240-ava-talk.zadn.vn/c/c/6/3/3/240/cd520d4d49a844b5abe6410e9e3dd9aa.jpg',
        bot_subtitle: 'Trợ lý AI MONA — đang online',
        policy_notice: 'Cuộc trò chuyện được lưu để cải thiện dịch vụ.',
        brand_footer: 'AI chăm sóc khách hàng bởi MONA',
        zalo_link: '',
        messenger_link: '',
        primary_color: '#0068ff'
    };
    var chatMessages = [];
    var pollInterval = null;

    // ── Inject CSS Styles ───────────────────────────────────────────────────
    var style = document.createElement('style');
    style.innerHTML = `
        .web-chat-launcher {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0084ff 0%, #0068ff 100%);
            box-shadow: 0 10px 28px rgba(0, 104, 255, 0.4);
            cursor: pointer;
            z-index: 999990;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 2px solid #ffffff;
        }
        .web-chat-launcher:hover {
            transform: scale(1.08);
            box-shadow: 0 14px 32px rgba(0, 104, 255, 0.5);
        }
        .web-chat-launcher svg {
            width: 28px;
            height: 28px;
            fill: #ffffff;
        }

        .web-chat-container {
            position: fixed;
            bottom: 96px;
            right: 24px;
            width: 380px;
            height: 620px;
            max-height: calc(100vh - 120px);
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18);
            z-index: 999995;
            display: none;
            flex-direction: column;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            transition: all 0.3s ease;
        }
        .web-chat-container.open {
            display: flex;
            animation: webChatSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes webChatSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Responsive Mobile Mode - Safe Area Insets & Dynamic Viewport Height (dvh) */
        @media (max-width: 600px) {
            .web-chat-container {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100vw !important;
                height: 100dvh !important;
                max-height: 100dvh !important;
                border-radius: 0 !important;
                z-index: 999999 !important;
                box-sizing: border-box !important;
                padding-top: max(0px, env(safe-area-inset-top, 0px)) !important;
                padding-bottom: max(0px, env(safe-area-inset-bottom, 0px)) !important;
            }
            .web-chat-launcher {
                bottom: 16px !important;
                right: 16px !important;
            }
            .web-chat-input-area {
                padding: 8px 12px !important;
            }
            .web-chat-footer-credit {
                padding-bottom: max(6px, env(safe-area-inset-bottom, 6px)) !important;
            }
        }

        /* Header */
        .web-chat-header {
            background: linear-gradient(135deg, #0084ff 0%, #0068ff 100%);
            padding: 14px 16px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .web-chat-header-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }
        .web-chat-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.8);
            flex-shrink: 0;
            background: #ffffff;
        }
        .web-chat-title {
            font-weight: 700;
            font-size: 16px;
            color: #ffffff;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .web-chat-subtitle {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.9);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .web-chat-online-dot {
            width: 8px;
            height: 8px;
            background: #22c55e;
            border-radius: 50%;
            display: inline-block;
        }
        .web-chat-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .web-chat-icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.18);
            border: none;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            font-size: 16px;
        }
        .web-chat-icon-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Yellow Policy Notice Banner */
        .web-chat-policy-banner {
            background: #fef9c3;
            border-bottom: 1px solid #fef08a;
            padding: 10px 14px;
            font-size: 12px;
            color: #854d0e;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-shrink: 0;
        }
        .web-chat-policy-btn {
            background: #0068ff;
            color: #ffffff;
            border: none;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        /* Messages Body */
        .web-chat-body {
            flex: 1;
            padding: 16px;
            overflow-y: auto;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .web-chat-msg {
            display: flex;
            flex-direction: column;
            max-width: 82%;
        }
        .web-chat-msg.bot {
            align-self: flex-start;
        }
        .web-chat-msg.user {
            align-self: flex-end;
        }
        .web-chat-bubble {
            padding: 12px 16px;
            font-size: 14px;
            line-height: 1.5;
            word-break: break-word;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .web-chat-msg.bot .web-chat-bubble {
            background: #ffffff;
            color: #1e293b;
            border-radius: 18px 18px 18px 4px;
            border: 1px solid #e2e8f0;
        }
        .web-chat-msg.user .web-chat-bubble {
            background: #0068ff;
            color: #ffffff;
            border-radius: 18px 18px 4px 18px;
        }

        /* Quick Channel Links */
        .web-chat-channels-row {
            padding: 8px 16px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            flex-shrink: 0;
        }
        .web-chat-channels-row a {
            color: #0068ff;
            font-weight: 700;
            text-decoration: none;
        }
        .web-chat-channels-row a:hover {
            text-decoration: underline;
        }

        /* Input Area - Compact, Sleek & Elegant */
        .web-chat-input-area {
            padding: 10px 14px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .web-chat-file-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f1f5f9;
            border: none;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 17px;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .web-chat-file-btn:hover {
            background: #e2e8f0;
            color: #0068ff;
        }
        .web-chat-input-box {
            flex: 1;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 8px 16px;
            font-size: 14px;
            line-height: 20px;
            outline: none;
            resize: none;
            height: 38px;
            min-height: 38px;
            max-height: 90px;
            box-sizing: border-box;
            font-family: inherit;
            background: #f8fafc;
            transition: all 0.2s;
            overflow-y: auto;
        }
        .web-chat-input-box:focus {
            border-color: #0068ff;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(0, 104, 255, 0.1);
        }
        .web-chat-send-btn {
            height: 38px;
            padding: 0 18px;
            background: #0068ff;
            color: #ffffff;
            border: none;
            border-radius: 20px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .web-chat-send-btn:hover {
            background: #0052cc;
        }

        /* Footer Credit */
        .web-chat-footer-credit {
            padding: 6px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            background: #ffffff;
            border-top: 1px solid #f8fafc;
            flex-shrink: 0;
        }

        /* Attachment Image Thumbnail */
        .web-chat-img-thumb {
            max-width: 100%;
            max-height: 220px;
            border-radius: 12px;
            margin-top: 6px;
            cursor: pointer;
            border: 1px solid rgba(0,0,0,0.1);
        }
        .web-chat-file-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(0, 0, 0, 0.06);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: inherit;
            text-decoration: none;
            margin-top: 6px;
        }
    `;
    document.head.appendChild(style);

    // ── Build DOM HTML Elements ─────────────────────────────────────────────
    var launcherHtml = `
        <div class="web-chat-launcher" id="webChatLauncher" title="Chat hỗ trợ">
            <svg viewBox="0 0 24 24" id="webChatLauncherIcon">
                <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/>
            </svg>
        </div>
    `;

    var containerHtml = `
        <div class="web-chat-container" id="webChatContainer">
            <!-- Header -->
            <div class="web-chat-header" id="webChatHeader">
                <div class="web-chat-header-info">
                    <img src="${widgetConfig.bot_avatar}" class="web-chat-avatar" id="webChatAvatar">
                    <div>
                        <div class="web-chat-title" id="webChatTitle">${widgetConfig.bot_name}</div>
                        <div class="web-chat-subtitle" id="webChatSubtitle">
                            <span class="web-chat-online-dot"></span>
                            <span>${widgetConfig.bot_subtitle}</span>
                        </div>
                    </div>
                </div>
                <div class="web-chat-header-actions">
                    <button class="web-chat-icon-btn" id="webChatFullscreenBtn" title="Toàn màn hình">⤢</button>
                    <button class="web-chat-icon-btn" id="webChatCloseBtn" title="Đóng">✕</button>
                </div>
            </div>

            <!-- Yellow Policy Banner -->
            <div class="web-chat-policy-banner" id="webChatPolicyBanner" style="${isPolicyAgreed ? 'display:none;' : ''}">
                <span id="webChatPolicyNotice">${widgetConfig.policy_notice}</span>
                <button class="web-chat-policy-btn" id="webChatPolicyBtn">Đồng ý</button>
            </div>

            <!-- Messages Body -->
            <div class="web-chat-body" id="webChatBody">
                <!-- Message bubbles will be rendered here -->
            </div>

            <!-- Channels Row -->
            <div class="web-chat-channels-row" id="webChatChannelsRow">
                Nói chuyện với em qua 
                <a href="${widgetConfig.zalo_link || '#'}" target="_blank" id="webChatZaloLink">Zalo</a> hoặc 
                <a href="${widgetConfig.messenger_link || '#'}" target="_blank" id="webChatMessengerLink">Messenger</a> đều được 💬
            </div>

            <!-- Input Area -->
            <div class="web-chat-input-area">
                <input type="file" id="webChatFileInput" style="display:none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt" onchange="window.WebChatUploadFile(this)">
                <button class="web-chat-file-btn" id="webChatFileTriggerBtn" title="Gửi ảnh hoặc file đính kèm">📎</button>
                <textarea class="web-chat-input-box" id="webChatInputBox" placeholder="Anh/chị cần hỗ trợ gì?" rows="1"></textarea>
                <button class="web-chat-send-btn" id="webChatSendBtn">Gửi</button>
            </div>

            <!-- Footer Credit -->
            <div class="web-chat-footer-credit" id="webChatBrandFooter">
                ${widgetConfig.brand_footer}
            </div>
        </div>
    `;

    // Append Elements to Body
    var divWrapper = document.createElement('div');
    divWrapper.innerHTML = launcherHtml + containerHtml;
    document.body.appendChild(divWrapper);

    // DOM Element References
    var launcherEl = document.getElementById('webChatLauncher');
    var containerEl = document.getElementById('webChatContainer');
    var closeBtn = document.getElementById('webChatCloseBtn');
    var fullscreenBtn = document.getElementById('webChatFullscreenBtn');
    var policyBtn = document.getElementById('webChatPolicyBtn');
    var policyBanner = document.getElementById('webChatPolicyBanner');
    var chatBody = document.getElementById('webChatBody');
    var inputBox = document.getElementById('webChatInputBox');
    var sendBtn = document.getElementById('webChatSendBtn');
    var fileTriggerBtn = document.getElementById('webChatFileTriggerBtn');
    var fileInput = document.getElementById('webChatFileInput');

    // ── API Interactions ────────────────────────────────────────────────────
    function initVisitor() {
        var url = apiBaseUrl + '/actions/web_chat_api.php?action=init_visitor&account_id=' + accountId + '&visitor_uuid=' + encodeURIComponent(visitorUuid);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    if (data.visitor_uuid) {
                        visitorUuid = data.visitor_uuid;
                        localStorage.setItem('web_chat_visitor_uuid_' + accountId, visitorUuid);
                    }
                    if (data.config) {
                        widgetConfig = data.config;
                        updateWidgetConfigUI();
                    }
                    if (data.messages) {
                        chatMessages = data.messages;
                        renderMessages();
                    }
                }
            })
            .catch(function(err) { console.error('Init Web Chat Widget error:', err); });
    }

    function updateWidgetConfigUI() {
        if (widgetConfig.bot_name) document.getElementById('webChatTitle').textContent = widgetConfig.bot_name;
        if (widgetConfig.bot_avatar) document.getElementById('webChatAvatar').src = widgetConfig.bot_avatar;
        if (widgetConfig.bot_subtitle) {
            var subEl = document.getElementById('webChatSubtitle');
            subEl.innerHTML = '<span class="web-chat-online-dot"></span><span>' + escapeHtml(widgetConfig.bot_subtitle) + '</span>';
        }
        if (widgetConfig.policy_notice) document.getElementById('webChatPolicyNotice').textContent = widgetConfig.policy_notice;
        if (widgetConfig.brand_footer) document.getElementById('webChatBrandFooter').textContent = widgetConfig.brand_footer;
        if (widgetConfig.zalo_link) document.getElementById('webChatZaloLink').href = widgetConfig.zalo_link;
        if (widgetConfig.messenger_link) document.getElementById('webChatMessengerLink').href = widgetConfig.messenger_link;

        // Apply Primary Color Dynamically
        if (widgetConfig.primary_color) {
            var color = widgetConfig.primary_color;
            launcherEl.style.background = 'linear-gradient(135deg, ' + color + ' 0%, ' + color + ' 100%)';
            document.getElementById('webChatHeader').style.background = 'linear-gradient(135deg, ' + color + ' 0%, ' + color + ' 100%)';
            sendBtn.style.background = color;
            if (policyBtn) policyBtn.style.background = color;

            var dynamicStyle = document.getElementById('webChatDynamicStyle');
            if (!dynamicStyle) {
                dynamicStyle = document.createElement('style');
                dynamicStyle.id = 'webChatDynamicStyle';
                document.head.appendChild(dynamicStyle);
            }
            dynamicStyle.innerHTML = '.web-chat-msg.user .web-chat-bubble { background: ' + color + ' !important; }';
        }

        // Apply Position & Offsets Dynamically (Sang trái, sang phải, cao/thấp)
        var pos = widgetConfig.widget_position || 'right';
        var bOffset = widgetConfig.bottom_offset !== undefined ? parseInt(widgetConfig.bottom_offset) : 24;
        var sOffset = widgetConfig.side_offset !== undefined ? parseInt(widgetConfig.side_offset) : 24;

        launcherEl.style.bottom = bOffset + 'px';
        containerEl.style.bottom = (bOffset + 72) + 'px';

        if (pos === 'left') {
            launcherEl.style.left = sOffset + 'px';
            launcherEl.style.right = 'auto';
            containerEl.style.left = sOffset + 'px';
            containerEl.style.right = 'auto';
        } else {
            launcherEl.style.right = sOffset + 'px';
            launcherEl.style.left = 'auto';
            containerEl.style.right = sOffset + 'px';
            containerEl.style.left = 'auto';
        }
    }

    function renderMessages() {
        chatBody.innerHTML = '';
        chatMessages.forEach(function(m) {
            var isUser = m.sender_type === 'user';
            var msgDiv = document.createElement('div');
            msgDiv.className = 'web-chat-msg ' + (isUser ? 'user' : 'bot');
            
            var bubbleDiv = document.createElement('div');
            bubbleDiv.className = 'web-chat-bubble';
            
            var contentHtml = autoLinkText(m.message);

            // Render Attachment if exists
            if (m.attachments) {
                try {
                    var att = typeof m.attachments === 'string' ? JSON.parse(m.attachments) : m.attachments;
                    if (att && att.url) {
                        if (att.type === 'image') {
                            contentHtml += '<div><img src="' + att.url + '" class="web-chat-img-thumb" onclick="window.open(\'' + att.url + '\')"></div>';
                        } else {
                            contentHtml += '<div><a href="' + att.url + '" class="web-chat-file-link" target="_blank" download>📎 ' + escapeHtml(att.name || 'Tập tin đính kèm') + '</a></div>';
                        }
                    }
                } catch(e) {}
            }

            bubbleDiv.innerHTML = contentHtml;
            msgDiv.appendChild(bubbleDiv);
            chatBody.appendChild(msgDiv);
        });
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function sendMessage() {
        var text = inputBox.value.trim();
        if (!text) return;

        inputBox.value = '';
        
        // Optimistic UI Append
        chatMessages.push({
            sender_type: 'user',
            sender_name: 'Khách hàng',
            message: text
        });
        renderMessages();

        var formData = new FormData();
        formData.append('action', 'send_visitor_msg');
        formData.append('account_id', accountId);
        formData.append('visitor_uuid', visitorUuid);
        formData.append('message', text);

        fetch(apiBaseUrl + '/actions/web_chat_api.php?action=send_visitor_msg', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'success' && data.messages) {
                chatMessages = data.messages;
                renderMessages();
            }
        })
        .catch(function(err) { console.error('Send message error:', err); });
    }

    // Global Upload Function
    window.WebChatUploadFile = function(input) {
        if (!input.files || input.files.length === 0) return;
        var file = input.files[0];

        var formData = new FormData();
        formData.append('action', 'upload_file');
        formData.append('account_id', accountId);
        formData.append('visitor_uuid', visitorUuid);
        formData.append('sender_type', 'user');
        formData.append('file', file);

        // Optimistic loading bubble
        chatMessages.push({
            sender_type: 'user',
            sender_name: 'Khách hàng',
            message: '⏳ Đang tải file lên...'
        });
        renderMessages();

        fetch(apiBaseUrl + '/actions/web_chat_api.php?action=upload_file', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                fetchMessages();
            } else {
                console.warn(data.msg || 'Tải file thất bại');
            }
        })
        .catch(function(err) { console.error('Upload error:', err); });

        input.value = '';
    };

    function fetchMessages() {
        if (!visitorUuid || !isChatOpen) return;
        var url = apiBaseUrl + '/actions/web_chat_api.php?action=get_messages&account_id=' + accountId + '&visitor_uuid=' + encodeURIComponent(visitorUuid);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'success' && data.messages) {
                    if (data.messages.length !== chatMessages.length) {
                        chatMessages = data.messages;
                        renderMessages();
                    }
                }
            })
            .catch(function(err) {});
    }

    // ── Helper Utilities ────────────────────────────────────────────────────
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function autoLinkText(text) {
        if (!text) return '';
        var escaped = escapeHtml(text);
        var urlRegex = /(https?:\/\/[^\s<]+)/gi;
        return escaped.replace(urlRegex, function(url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" style="color:#0068ff; text-decoration:underline;">' + url + '</a>';
        }).replace(/\n/g, '<br>');
    }

    // ── Event Listeners ─────────────────────────────────────────────────────
    launcherEl.addEventListener('click', function() {
        isChatOpen = !isChatOpen;
        if (isChatOpen) {
            containerEl.classList.add('open');
            fetchMessages();
            if (!pollInterval) {
                pollInterval = setInterval(fetchMessages, 3000);
            }
        } else {
            containerEl.classList.remove('open');
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }
    });

    closeBtn.addEventListener('click', function() {
        isChatOpen = false;
        containerEl.classList.remove('open');
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    });

    fullscreenBtn.addEventListener('click', function() {
        if (containerEl.style.width === '100%') {
            containerEl.style.width = '380px';
            containerEl.style.height = '620px';
            containerEl.style.borderRadius = '20px';
        } else {
            containerEl.style.width = '100%';
            containerEl.style.height = '100%';
            containerEl.style.borderRadius = '0';
        }
    });

    policyBtn.addEventListener('click', function() {
        policyBanner.style.display = 'none';
        localStorage.setItem('web_chat_policy_agreed_' + accountId, '1');
    });

    fileTriggerBtn.addEventListener('click', function() {
        fileInput.click();
    });

    sendBtn.addEventListener('click', sendMessage);

    inputBox.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Auto Init on Page Load
    initVisitor();
})();
