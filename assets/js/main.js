/**
 * NDRI PRIME - Vanilla JavaScript Application Logic
 */

document.addEventListener('DOMContentLoaded', function () {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Confirmation Modals handler for destructive or workflow actions
    const confirmTriggers = document.querySelectorAll('[data-confirm]');
    confirmTriggers.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const message = btn.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // Auto-dismiss alerts after 6 seconds
    const autoAlerts = document.querySelectorAll('.alert-dismissible');
    autoAlerts.forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 6000);
    });

    // Dynamic Co-PI row addition in proposal forms
    window.addCoPiRow = window.addCoPiRow || function () {
        const copiContainer = document.getElementById('copi-container');
        if (!copiContainer) return;
        const rowCount = copiContainer.querySelectorAll('.copi-row').length;
        if (rowCount >= 10) {
            alert('Maximum 10 Co-PIs allowed per proposal.');
            return;
        }

        const newRow = document.createElement('div');
        newRow.className = 'row g-2 mb-2 copi-row align-items-center';
        newRow.innerHTML = `
            <div class="col-md-3">
                <input type="text" name="copi_name[]" class="form-control form-control-sm" placeholder="Co-PI Name (e.g. Dr. K. S. Sharma)" required>
            </div>
            <div class="col-md-4">
                <input type="text" name="copi_institution[]" class="form-control form-control-sm" placeholder="Institution / Division" required>
            </div>
            <div class="col-md-2">
                <input type="text" name="copi_designation[]" class="form-control form-control-sm" placeholder="Designation">
            </div>
            <div class="col-md-2">
                <input type="email" name="copi_email[]" class="form-control form-control-sm" placeholder="name@icar.org.in">
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-outline-danger btn-sm remove-copi-btn" onclick="removeCoPiRow(this)" title="Remove Co-PI">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        copiContainer.appendChild(newRow);
    };

    window.removeCoPiRow = window.removeCoPiRow || function (btn) {
        if (!btn) return;
        const row = btn.closest('.copi-row');
        if (!row) return;
        const container = document.getElementById('copi-container');
        const rows = container ? container.querySelectorAll('.copi-row') : [];
        if (rows.length <= 1) {
            row.querySelectorAll('input').forEach(function(input) { input.value = ''; });
        } else {
            row.remove();
        }
    };

    const addCoPiBtn = document.getElementById('add-copi-btn');
    if (addCoPiBtn && !addCoPiBtn.hasAttribute('onclick')) {
        addCoPiBtn.addEventListener('click', function (e) {
            e.preventDefault();
            window.addCoPiRow();
        });
    }

    const copiContainer = document.getElementById('copi-container');
    if (copiContainer) {
        copiContainer.addEventListener('click', function (e) {
            const btn = e.target.closest('.remove-copi-btn');
            if (btn && !btn.hasAttribute('onclick')) {
                window.removeCoPiRow(btn);
            }
        });
    }
});
