<?php if (!defined('FOOTER_INCLUDED')) define('FOOTER_INCLUDED', true); ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    <script src="../assets/js/script.js"></script>

    <script>
        $(document).ready(function() {
            $('.data-table').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/km.json' },
                responsive: true
            });
            $('.datepicker').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
            });
        });
    </script>

    <!-- FULL KHMER FONT SUPPORT -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Khmer&display=swap" rel="stylesheet">
    <style>
        body, input, button, select, textarea, .btn, .form-control, .table, .modal-title, .card, .alert, h1, h2, h3, h4, h5, h6, p, span, div, a, li, td, th, label, .dataTables_info, .page-link {
            font-family: 'Noto Sans Khmer', 'Khmer', sans-serif !important;
            line-height: 1.8 !important;
        }
        .btn, .form-control { font-size: 16px !important; padding: 10px 16px !important; }
    </style>

    <?php if (isset($_SESSION['user_id'])): ?>
    <!-- AI CHAT 100% WORKING -->
    <div id="aiChatBubble" class="position-fixed" style="bottom:25px; right:25px; z-index:9999;">
        <button class="btn btn-warning btn-lg rounded-circle shadow-lg" style="width:70px;height:70px;font-size:32px;" 
                onclick="document.getElementById('aiChatWindow').style.display='block'">🤖</button>
    </div>

    <div id="aiChatWindow" class="position-fixed card shadow-lg border-0" 
         style="bottom:110px; right:25px; width:380px; max-width:95vw; height:620px; display:none; z-index:9999; border-radius:20px; overflow:hidden;">
        <div class="card-header bg-warning text-dark text-center fw-bold fs-5">
            🏗️ ជំនួយការ AI ខ្មែរ
            <button class="btn-close float-end" onclick="document.getElementById('aiChatWindow').style.display='none'"></button>
        </div>
        <div id="aiMessages" class="p-3" style="height:500px; overflow-y:auto; background:#f8f9fa; font-size:16px;"></div>
        <div class="card-footer bg-white p-3 border-top">
            <div class="input-group">
                <input type="text" id="aiInput" class="form-control form-control-lg border-warning" placeholder="សួរជាភាសាខ្មែរ..." 
                       onkeypress="if(event.key==='Enter') sendAI()">
                <button class="btn btn-warning btn-lg" onclick="sendAI()">សួរ</button>
                <button class="btn btn-success btn-lg ms-2" onclick="startVoice()">🎤</button>
            </div>
        </div>
    </div>

    <script>
        setTimeout(() => addMsg('សួស្តីបង <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>! 🏗️<br>សួរឈ្មោះសម្ភារៈអ្វីក៏បាន<br>• នៅណា?<br>• ពី supplier ណា?<br>• ជិតអស់អត់?<br>• មានអ្វីខូចទេ?'), 1000);

        function addMsg(text, isUser = false) {
            const div = document.createElement('div');
            div.className = isUser ? 'text-end mb-3' : 'text-start mb-3';
            div.innerHTML = `<div class="${isUser?'bg-warning text-dark':'bg-light'} rounded-3 px-4 py-3 d-inline-block" style="max-width:85%; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                ${text.replace(/\n/g,'<br>')}
            </div>`;
            document.getElementById('aiMessages').appendChild(div);
            div.scrollIntoView({behavior:'smooth'});
        }

        function sendAI() {
            const input = document.getElementById('aiInput');
            const msg = input.value.trim();
            if (!msg) return;
            addMsg(msg, true);
            input.value = '';

            const base = window.location.href.split('/').slice(0,3).join('/') + '/';
            fetch(base + 'ai_chat.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'message=' + encodeURIComponent(msg)
            })
            .then(r => { if (!r.ok) throw ''; return r.json(); })
            .then(data => addMsg(data.reply))
            .catch(() => addMsg('មានបញ្ហាបណ្តាញ។ សាកម្តងទៀតបានទេ? 🙏'));
        }

        function startVoice() {
            const r = new (window.SpeechRecognition || window.webkitSpeechRecognition)();
            if (!r) return alert('Browser មិនគាំទ្រ voice');
            r.lang = 'km-KH';
            r.start();
            r.onresult = e => { document.getElementById('aiInput').value = e.results[0][0].transcript; sendAI(); };
        }

        document.addEventListener('keydown', e => { if (e.ctrlKey && e.key === 'k') { e.preventDefault(); document.getElementById('aiChatWindow').style.display = 'block'; } });
    </script>
    <?php endif; ?>
</html>