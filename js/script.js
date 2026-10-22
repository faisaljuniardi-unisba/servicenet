document.addEventListener('DOMContentLoaded', function() {
    // Initialize signature pads
    const canvasIT = document.querySelector('#signature-pad-it canvas');
    const canvasPengguna = document.querySelector('#signature-pad-pengguna canvas');
    
    if (canvasIT && canvasPengguna) {
        const signaturePadIT = new SignaturePad(canvasIT);
        const signaturePadPengguna = new SignaturePad(canvasPengguna);
        
        // Adjust canvas size
        function resizeCanvas(canvas) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
        }
        
        resizeCanvas(canvasIT);
        resizeCanvas(canvasPengguna);
        
        // Clear buttons
        document.querySelectorAll('.btn-clear').forEach(button => {
            button.addEventListener('click', function() {
                const pad = this.getAttribute('data-pad');
                if (pad === 'it') {
                    signaturePadIT.clear();
                    document.getElementById('signature-it-input').value = '';
                } else {
                    signaturePadPengguna.clear();
                    document.getElementById('signature-pengguna-input').value = '';
                }
            });
        });
        
        // Update hidden inputs before form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!signaturePadIT.isEmpty()) {
                document.getElementById('signature-it-input').value = signaturePadIT.toDataURL();
            }
            
            if (!signaturePadPengguna.isEmpty()) {
                document.getElementById('signature-pengguna-input').value = signaturePadPengguna.toDataURL();
            }
        });
        
        // Auto-resize on window resize
        window.addEventListener('resize', function() {
            resizeCanvas(canvasIT);
            resizeCanvas(canvasPengguna);
        });
    }
});