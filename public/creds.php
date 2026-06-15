<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clip Matters - Credentials</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
            line-height: 1.7;
            padding: 40px 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.8em;
            color: white;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            margin-bottom: 10px;
        }
        
        .header .subtitle {
            font-size: 1.2em;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 35px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        
        .card h2 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.8em;
            color: #764ba2;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .warning-box {
            background: linear-gradient(135deg, #ff6b6b20 0%, #ee5a5a20 100%);
            border-left: 4px solid #e74c3c;
            padding: 20px 25px;
            border-radius: 0 15px 15px 0;
            margin: 20px 0;
        }
        
        .warning-box strong {
            color: #c0392b;
            font-size: 1.1em;
        }
        
        .info-box {
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            border-left: 4px solid #764ba2;
            padding: 20px 25px;
            border-radius: 0 15px 15px 0;
            margin: 20px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        a:hover {
            text-decoration: underline;
        }
        
        code {
            background: #f4f4f4;
            padding: 3px 8px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            color: #e94560;
        }
        
        .badge {
            display: inline-block;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 5px 15px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.85em;
        }
        
        .badge.admin {
            background: linear-gradient(135deg, #e94560 0%, #ff6b6b 100%);
        }
        
        .badge.user {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        @media print {
            body {
                background: white;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
        
        /* Password Protection Styles */
        .password-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .password-box {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 400px;
            width: 90%;
            text-align: center;
        }
        
        .password-box h2 {
            color: #764ba2;
            margin-bottom: 10px;
            font-size: 1.8em;
        }
        
        .password-box p {
            color: #666;
            margin-bottom: 25px;
        }
        
        .password-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1em;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin-bottom: 15px;
            transition: border-color 0.3s;
        }
        
        .password-input:focus {
            outline: none;
            border-color: #764ba2;
        }
        
        .password-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Plus Jakarta Sans', sans-serif;
            transition: transform 0.2s;
        }
        
        .password-submit:hover {
            transform: translateY(-2px);
        }
        
        .password-error {
            color: #e74c3c;
            margin-top: 15px;
            font-size: 0.9em;
            display: none;
        }
        
        .content-protected {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Password Protection Overlay -->
    <div class="password-overlay" id="passwordOverlay">
        <div class="password-box">
            <h2>🔐 Protected Content</h2>
            <p>This document contains sensitive credentials.<br>Please enter the password to continue.</p>
            <input type="password" id="passwordInput" class="password-input" placeholder="Enter password" autofocus>
            <button onclick="checkPassword()" class="password-submit">Unlock</button>
            <p class="password-error" id="passwordError">❌ Incorrect password. Please try again.</p>
        </div>
    </div>
    
    <div class="container content-protected" id="protectedContent">
        <!-- Header -->
        <div class="header">
            <h1>🔐 Clip Matters Credentials</h1>
            <p class="subtitle">Access Information & Service Credentials</p>
        </div>
        
        <!-- Warning Box -->
        <div class="card">
            <div class="warning-box">
                <strong>⚠️ CONFIDENTIAL INFORMATION</strong>
                <p style="margin-top: 10px;">This document contains sensitive credentials and access information. Handle with extreme care and do not share publicly or with unauthorized personnel.</p>
            </div>
        </div>
        
        <!-- Admin Panel Access -->
        <div class="card">
            <h2>🎛️ Admin Panel Access</h2>
            <div class="info-box">
                <p><strong>Admin Panel URL:</strong> <a href="https://clip.digitalmatters.pk" target="_blank">https://clip.digitalmatters.pk</a></p>
            </div>
            
            <table>
                <tr>
                    <th>Role</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Password</th>
                    <th>Permissions</th>
                </tr>
                <tr>
                    <td><span class="badge admin">ADMIN</span></td>
                    <td><strong>Clip Matter Admin</strong></td>
                    <td><code>clip.matters@digitalmatters.pk</code></td>
                    <td><code>Cl!pM@tters2026</code></td>
                    <td>Full system access, video management, user management, settings</td>
                </tr>
                <tr>
                    <td><span class="badge user">USER</span></td>
                    <td><strong>Clip Matter User</strong></td>
                    <td><code>clip.user@clipmatters.com</code></td>
                    <td><code>User@2026</code></td>
                    <td>Search videos, view content, create clips</td>
                </tr>
            </table>
            
            <div class="info-box">
                <strong>🔒 Security Note:</strong> Please change these passwords after first login for security purposes.
            </div>
        </div>
        
        <!-- External Services -->
        <div class="card">
            <h2>☁️ External Service Credentials</h2>
            <p style="margin-bottom: 20px;">These are the external services integrated with Clip Matters platform.</p>
            
            <table>
                <tr>
                    <th>Service</th>
                    <th>Login Method</th>
                    <th>Username / Email</th>
                    <th>Password</th>
                    <th>Login URL</th>
                </tr>
                <tr>
                    <td><strong>Assembly AI</strong></td>
                    <td>Login With Google</td>
                    <td><code>clipmatter@gmail.com</code></td>
                    <td><code>Cllpm@tters</code></td>
                    <td><a href="https://www.assemblyai.com/dashboard/login" target="_blank">assemblyai.com/dashboard/login</a></td>
                </tr>
                <tr>
                    <td><strong>Railway Cloud</strong></td>
                    <td>Login With Google</td>
                    <td><code>clipmatter@gmail.com</code></td>
                    <td><code>Cllpm@tters</code></td>
                    <td><a href="https://railway.com/" target="_blank">railway.com</a></td>
                </tr>
                <tr>
                    <td><strong>DropBox</strong></td>
                    <td>Login With Google Or Email</td>
                    <td><code>clipmatter@gmail.com</code></td>
                    <td><code>Cllpm@tters1</code></td>
                    <td><a href="https://www.dropbox.com/login" target="_blank">dropbox.com/login</a></td>
                </tr>
                <tr>
                    <td><strong>Open AI</strong></td>
                    <td>Login With Google</td>
                    <td><code>clipmatter@gmail.com</code></td>
                    <td><code>Cllpm@tters</code></td>
                    <td><a href="https://auth.openai.com/log-in" target="_blank">auth.openai.com/log-in</a></td>
                </tr>
                <tr>
                    <td><strong>Pyannote AI</strong></td>
                    <td>Login with Email</td>
                    <td><code>clip.matters@digitalmatters.pk</code></td>
                    <td>Login Code At this Email</td>
                    <td><a href="https://dashboard.pyannote.ai/signin" target="_blank">dashboard.pyannote.ai/signin</a></td>
                </tr>
                <tr>
                    <td><strong>Qdrant Cloud</strong></td>
                    <td>Login With Google</td>
                    <td><code>clipmatter@gmail.com</code></td>
                    <td><code>Cllpm@tters</code></td>
                    <td><a href="https://qdrant.tech/documentation/cloud-intro/" target="_blank">qdrant.tech/documentation/cloud-intro</a></td>
                </tr>
                <tr>
                    <td><strong>Cloudways</strong></td>
                    <td>Login With Email</td>
                    <td><code>clipmatter@gmail.com</code></td>
                    <td><code>Cllpm@tters1</code></td>
                    <td><a href="https://platform.cloudways.com/login" target="_blank">platform.cloudways.com/login</a></td>
                </tr>
            </table>
        </div>
        
        <!-- Quick Reference -->
        <div class="card">
            <h2>📌 Quick Reference</h2>
            <table>
                <tr>
                    <th>Item</th>
                    <th>Details</th>
                </tr>
                <tr>
                    <td><strong>Primary Admin Email</strong></td>
                    <td><code>clip.matters@digitalmatters.pk</code></td>
                </tr>
                <tr>
                    <td><strong>Primary Service Email</strong></td>
                    <td><code>clipmatter@gmail.com</code></td>
                </tr>
                <tr>
                    <td><strong>Main Application URL</strong></td>
                    <td><a href="https://clip.digitalmatters.pk" target="_blank">https://clip.digitalmatters.pk</a></td>
                </tr>
                <tr>
                    <td><strong>Tech Documentation</strong></td>
                    <td><a href="tech.html" target="_blank">tech.html</a></td>
                </tr>
                <tr>
                    <td><strong>Admin Guide</strong></td>
                    <td><a href="admin.html" target="_blank">admin.html</a></td>
                </tr>
                <tr>
                    <td><strong>User Guide</strong></td>
                    <td><a href="user.html" target="_blank">user.html</a></td>
                </tr>
            </table>
        </div>
        
        <!-- Footer -->
        <div class="card" style="text-align: center;">
            <p style="color: #888; font-size: 0.9em;">© 2026 Swishtag. All rights reserved.</p>
            <p style="color: #888; font-size: 0.85em; margin-top: 5px;">Document Version: 1.0 | Last Updated: February 2, 2026</p>
        </div>
    </div>
    
    <script>
        // Password Protection with Base64 Obfuscation
        // Obfuscated password: ClipMatters!9X#Q4@C
        const obfuscatedPassword = btoa('ClipMatters!9X#Q4@C').split('').reverse().join('');
        
        async function checkPassword() {
            const input = document.getElementById('passwordInput');
            const error = document.getElementById('passwordError');
            const overlay = document.getElementById('passwordOverlay');
            const content = document.getElementById('protectedContent');
            const submitBtn = document.querySelector('.password-submit');
            
            // Disable button during processing
            submitBtn.disabled = true;
            submitBtn.textContent = 'Verifying...';
            
            try {
                // Deobfuscate and compare
                const actualPassword = atob(obfuscatedPassword.split('').reverse().join(''));
                
                if (input.value === actualPassword) {
                    overlay.style.display = 'none';
                    content.style.display = 'block';
                    content.classList.remove('content-protected');
                    
                    // Store session with timestamp
                    sessionStorage.setItem('clipMattersAuth', Date.now());
                } else {
                    error.style.display = 'block';
                    input.value = '';
                    input.focus();
                    
                    // Shake animation
                    input.style.animation = 'shake 0.5s';
                    setTimeout(() => {
                        input.style.animation = '';
                    }, 500);
                }
            } catch (err) {
                console.error('Authentication error:', err);
                error.textContent = '❌ Authentication failed. Please try again.';
                error.style.display = 'block';
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Unlock';
            }
        }
        
        // Check if already authenticated in this session
        window.addEventListener('DOMContentLoaded', function() {
            const authTime = sessionStorage.getItem('clipMattersAuth');
            if (authTime && (Date.now() - parseInt(authTime)) < 3600000) { // Valid for 1 hour
                document.getElementById('passwordOverlay').style.display = 'none';
                document.getElementById('protectedContent').style.display = 'block';
                document.getElementById('protectedContent').classList.remove('content-protected');
            }
        });
        
        // Allow Enter key to submit
        document.getElementById('passwordInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                checkPassword();
            }
        });
        
        // Prevent inspect element and console access attempts
        document.addEventListener('contextmenu', function(e) {
            if (!sessionStorage.getItem('clipMattersAuth')) {
                e.preventDefault();
            }
        });
        
        // Disable F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U
        document.addEventListener('keydown', function(e) {
            if (!sessionStorage.getItem('clipMattersAuth')) {
                if (e.key === 'F12' || 
                    (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J' || e.key === 'C')) ||
                    (e.ctrlKey && e.key === 'U')) {
                    e.preventDefault();
                }
            }
        });
    </script>
    
    <style>
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
    </style>
</body>
</html>
