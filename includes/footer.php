<?php if (!defined('FOOTER_INCLUDED')) define('FOOTER_INCLUDED', true); ?>
        </div> <!-- End of container-fluid -->
    </div> <!-- End of main-content -->

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Datepicker -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    
    <!-- Custom Scripts -->
    <script src="../assets/js/script.js"></script>
    
    <!-- Initialize DataTables + Datepicker -->
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

    <!-- ============================================= -->
    <!-- KHMER AI CHATBOT – FINAL 2025 (appears after login) -->
    <!-- ============================================= -->
    <div id="aiChatBubble" class="position-fixed" style="bottom:25px; right:25px; z-index:9999;">
        <button class="btn btn-warning btn-lg rounded-circle shadow-lg d-flex align-items-center justify-content-center" 
                style="width:70px; height:70px; font-size:32px;" onclick="toggleAIChat()">
            🤖
        </button>
    </div>

    <div id="aiChatWindow" class="position-fixed card shadow-lg border-0" 
         style="bottom:110px; right:25px; width:380px; max-width:95vw; height:620px; display:none; z-index:9999; border-radius:20px; overflow:hidden;">
        <div class="card-header bg-warning text-dark text-center fw-bold fs-5">
            🏗️ ជំនួយការ AI ខ្មែរ (សួរអ្វីក៏បាន!)
            <button class="btn-close float-end" onclick="toggleAIChat()"></button>
        </div>
        <div id="aiMessages" class="p-3" style="height:500px; overflow-y:auto; background:#f8f9fa; font-size:15px;"></div>
        <div class="card-footer bg-white p-3 border-top">
            <div class="input-group">
                <input type="text" id="aiInput" class="form-control form-control-lg border-warning" 
                       placeholder="សួរជាភាសាខ្មែរ..." onkeypress="if(event.key==='Enter') sendMessage()">
                <button class="btn btn-warning btn-lg" onclick="sendMessage()">សួរ</button>
                <button class="btn btn-success btn-lg ms-2" onclick="startVoice()">🎤</button>
            </div>
        </div>
    </div>

    <script>
        // Toggle chat
        function toggleAIChat() {
            const win = document.getElementById('aiChatWindow');
            win.style.display = (win.style.display === 'none' || win.style.display === '') ? 'block' : 'none';
        }

        // Add message to chat
        function addMessage(text, isUser = false) {
            const div = document.createElement('div');
            div.className = isUser ? 'text-end mb-3' : 'text-start mb-3';
            div.innerHTML = `<div class="${isUser?'bg-warning text-dark':'bg-light'} rounded-3 px-4 py-3 d-inline-block" style="max-width:85%; box-shadow:0 2px 8px rgba(0,0,0,0.1); word-wrap:break-word;">
                ${text.replace(/\n/g, '<br>')}
            </div>`;
            document.getElementById('aiMessages').appendChild(div);
            div.scrollIntoView({behavior: 'smooth'});
        }

        // Send message to ai_chat.php
        function sendMessage() {
            const input = document.getElementById('aiInput');
            const msg = input.value.trim();
            if (!msg) return;
            addMessage(msg, true);
            input.value = '';

            fetch('../ai_chat.php', {  // ← works from any folder
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'message=' + encodeURIComponent(msg)
            })
            .then(r => r.json())
            .then(data => addMessage(data.reply))
            .catch(() => addMessage('មានបញ្ហាបន្តិច។ សាកម្តងទៀតបានទេ? 🙏'));
        }

        // Voice recognition (Khmer)
        function startVoice() {
            if (!('SpeechRecognition' in window || 'webkitSpeechRecognition' in window)) {
                alert('Browser របស់បងមិនទាន់គាំទ្រ voice ទេ');
                return;
            }
            const recognition = new (window.SpeechRecognition || window.webkitSpeechRecognition)();
            recognition.lang = 'km-KH';
            recognition.interimResults = false;
            recognition.start();
            recognition.onresult = function(e) {
                document.getElementById('aiInput').value = e.results[0][0].transcript;
                sendMessage();
            };
        }

        // Welcome message (only show after login)
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['user_id'])): ?>  // ← only logged-in users see it
                setTimeout(() => {
                    addMessage('សួស្តីបង <?php echo $_SESSION['username'] ?? ""; ?>! 🏗️<br>សួរឈ្មោះសម្ភារៈអ្វីក៏បាន<br>• នៅណា?<br>• ពី supplier ណា?<br>• ជិតអស់អត់?<br>• មានអ្វីខូចទេ?');
                }, 1000);
            <?php endif; ?>
        });

        // Keyboard shortcut Ctrl+K
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                toggleAIChat();
            }
        });
    </script>

    <style>
        #aiMessages::-webkit-scrollbar { width: 8px; }
        #aiMessages::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        #aiMessages::-webkit-scrollbar-thumb { background: #ffc107; border-radius: 10px; }
    </style>

</html>