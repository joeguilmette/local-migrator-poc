(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // Wire copy install command button
        var copyInstallBtn = document.getElementById('localpoc-copy-install');
        if (copyInstallBtn) {
            copyInstallBtn.addEventListener('click', function() {
                copyText('localpoc-install-cmd', copyInstallBtn);
            });
        }

        // Wire copy download command button
        var copyCommandBtn = document.getElementById('localpoc-copy-command');
        if (copyCommandBtn) {
            copyCommandBtn.addEventListener('click', function() {
                copyText('localpoc-cli-command', copyCommandBtn);
            });
        }
    });

    function copyText(elementId, button) {
        var element = document.getElementById(elementId);
        if (!element) return;

        var text = element.textContent || element.innerText;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                showSuccess(button);
            }).catch(function() {
                fallbackCopy(text, button);
            });
        } else {
            fallbackCopy(text, button);
        }
    }

    function fallbackCopy(text, button) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-999999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            showSuccess(button);
        } catch (err) {
            // Silently fail
        }

        document.body.removeChild(textarea);
    }

    function showSuccess(button) {
        var originalText = button.textContent;
        button.textContent = 'Copied!';
        button.disabled = true;

        setTimeout(function() {
            button.textContent = originalText;
            button.disabled = false;
        }, 2000);
    }
})();