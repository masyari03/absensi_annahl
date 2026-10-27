
;

        function toggleSidebarDropdown(id, e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            const submenu = document.getElementById(id);
            if (!submenu) return;
            const btn = document.getElementById('btn_' + id);
            const isOpen = submenu.classList.contains('show');

            if (isOpen) {
                submenu.classList.remove('show');
                if (btn) btn.classList.remove('open');
                sessionStorage.setItem('ais_nav_' + id, 'closed');
            } else {
                submenu.classList.add('show');
                if (btn) btn.classList.add('open');
                sessionStorage.setItem('ais_nav_' + id, 'open');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.sidebar-submenu').forEach(function(sub) {
                const id = sub.id;
                const btn = document.getElementById('btn_' + id);
                const savedState = sessionStorage.getItem('ais_nav_' + id);
                if (savedState === 'open' && !sub.classList.contains('show')) {
                    sub.classList.add('show');
                    if (btn) btn.classList.add('open');
                } else if (savedState === 'closed' && btn && !btn.classList.contains('has-active-child')) {
                    sub.classList.remove('show');
                    btn.classList.remove('open');
                }
            });
        });

        function handleScannerMenuClick(e) {
            sessionStorage.setItem('ais_fullscreen', '1');
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(function(){});
            }
        }
        
;

// --- LOGIKA DROPDOWN & MODAL ---
function toggleDropdown(e) {
    let menu = document.getElementById('profileMenu');
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    e.stopPropagation();
}

document.addEventListener('click', function() {
    document.getElementById('profileMenu').style.display = 'none';
});

function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
    document.getElementById('profileMenu').style.display = 'none';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// --- FUNGSI GLOBAL PENGONTROL SIDEBAR (DESKTOP & MOBILE) ---
let _sidebarBusy = false;

window.openSidebarMenu = function() {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (sb) sb.classList.add('active');
    if (bd) bd.classList.add('show');
    document.body.style.overflow = 'hidden';
};

window.closeSidebarMenu = function(e) {
    if (e) {
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
    }
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    
    // Tutup drawer di layar HP / tablet
    if (sb) sb.classList.remove('active');
    if (bd) bd.classList.remove('show');
    document.body.style.overflow = '';
    
    // Jika di layar desktop (> 992px), tombol X menyembunyikan sidebar desktop
    if (window.innerWidth > 992) {
        document.body.classList.add('sidebar-closed');
        try { localStorage.setItem('sidebarState', 'closed'); } catch(err) {}
    }
};

window.toggleSidebarMenu = function(e) {
    if (e) {
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
    }
    
    // Cegah double triggering dari sentuhan mobile / klik ganda
    if (_sidebarBusy) return;
    _sidebarBusy = true;
    setTimeout(() => { _sidebarBusy = false; }, 250);

    const sb = document.getElementById('sidebar');
    if (window.innerWidth <= 992) {
        if (sb && sb.classList.contains('active')) {
            window.closeSidebarMenu(e);
        } else {
            window.openSidebarMenu();
        }
    } else {
        document.body.classList.toggle('sidebar-closed');
        const isClosed = document.body.classList.contains('sidebar-closed');
        try { localStorage.setItem('sidebarState', isClosed ? 'closed' : 'open'); } catch(err) {}
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (window.innerWidth > 992) {
        try {
            if (localStorage.getItem('sidebarState') === 'closed') {
                document.body.classList.add('sidebar-closed');
            }
        } catch(err) {}
    } else {
        document.body.classList.remove('sidebar-closed');
    }
});

window.addEventListener('resize', () => {
    if (window.innerWidth <= 992) {
        document.body.classList.remove('sidebar-closed');
    } else {
        // Jika beralih ke desktop, reset drawer mobile
        const sb = document.getElementById('sidebar');
        const bd = document.getElementById('sidebarBackdrop');
        if (sb) sb.classList.remove('active');
        if (bd) bd.classList.remove('show');
        document.body.style.overflow = '';
        try {
            if (localStorage.getItem('sidebarState') === 'closed') {
                document.body.classList.add('sidebar-closed');
            }
        } catch(err) {}
    }
});

// Menutup sidebar dengan tombol Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const sb = document.getElementById('sidebar');
        if (sb && sb.classList.contains('active')) {
            window.closeSidebarMenu();
        }
    }
});

// Activity Heartbeat & Background Auto Reminder WA (setiap 60-120 detik)
(function() {
    let lastPing = Date.now();
    let lastWaCheck = 0;
    function sendHeartbeat() {
        if (Date.now() - lastPing < 40000) return;
        lastPing = Date.now();
        fetch('/public/kelola/activity.php?action=heartbeat', { method: 'POST' }).catch(function(){});
        
        // Pengecekan pesan WA otomatis (Pesan 2 & 4) setiap 2 menit jika aktif
        if (Date.now() - lastWaCheck > 120000) {
            lastWaCheck = Date.now();
            fetch('/public/whatsapp/check_reminders.php').catch(function(){});
        }
    }
    setInterval(sendHeartbeat, 60000);
    window.addEventListener('focus', sendHeartbeat);
    window.addEventListener('click', sendHeartbeat);
})();

;

function toggleAddDropdown() {
    const dd = document.getElementById('dropdownAddSupervisi');
    dd.style.display = (dd.style.display === 'none' || dd.style.display === '') ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#dropdownAddSupervisi') && !e.target.closest('button[onclick="toggleAddDropdown()"]')) {
        const dd = document.getElementById('dropdownAddSupervisi');
        if (dd) dd.style.display = 'none';
    }
});

function openPrintReportModal(sessionId) {
    fetch('reports.php?ajax=get_report_detail&session_id=' + sessionId)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Gagal memuat detail raport.');
                return;
            }
            const s = data.session;
            document.getElementById('rptTeacherName').innerText = s.teacher_name;
            document.getElementById('rptObsDate').innerText = s.observation_date;
            document.getElementById('rptSubjectName').innerText = s.subject_name;
            document.getElementById('rptClassName').innerText = s.class_name;
            document.getElementById('rptRoom').innerText = s.room_location || '-';
            document.getElementById('rptObserverName').innerText = s.observer_name;
            document.getElementById('rptTypeName').innerText = s.type_name;

            document.getElementById('rptTotalScoreDisplay').innerText = Math.round(s.total_score) + ' / ' + Math.round(s.max_possible_score) + ' (' + parseFloat(s.percentage_score).toFixed(2) + '%)';
            document.getElementById('rptPredicateDisplay').innerText = s.predicate_name;

            document.getElementById('rptNotesGood').innerText = s.notes_good_practice || 'Tidak ada catatan khusus.';
            document.getElementById('rptNotesGrowth').innerText = s.notes_growth_plan || 'Pertahankan capaian yang ada.';
            document.getElementById('rptNotesReflection').innerText = s.notes_teacher_reflection || '-';

            document.getElementById('rptSignTeacher').innerText = s.signed_teacher_name || s.teacher_name;
            document.getElementById('rptSignObserver').innerText = s.signed_observer_name || s.observer_name;
            document.getElementById('rptSignPrincipal').innerText = s.signed_principal_name || 'Awang Darmawan, SS';

            // Render Tabel Butir Per Kategori
            let tableHtml = '<table style="width: 100%; border-collapse: collapse; font-size: 11px;" border="1">';
            tableHtml += '<tr style="background: #f1f5f9; text-align: center;">';
            tableHtml += '<th style="padding: 6px; width: 35px;">No</th>';
            tableHtml += '<th style="padding: 6px; width: 130px;">Aspek / Kode</th>';
            tableHtml += '<th style="padding: 6px;">Indikator Penilaian Pembelajaran</th>';
            tableHtml += '<th style="padding: 6px; width: 65px;">Skor (1-4)</th>';
            tableHtml += '</tr>';

            let globalNo = 1;
            data.categories.forEach(cat => {
                tableHtml += `<tr style="background: #e2e8f0; font-weight: bold;">
                    <td colspan="3" style="padding: 6px 8px;">${cat.category_name}</td>
                    <td style="padding: 6px; text-align: center;">${Math.round(cat.subtotal_score)} / ${Math.round(cat.subtotal_max)}</td>
                </tr>`;

                cat.items.forEach(it => {
                    tableHtml += `<tr>
                        <td style="padding: 4px 6px; text-align: center;">${globalNo++}</td>
                        <td style="padding: 4px 6px;"><strong>${it.aspek_label || it.code || '-'}</strong></td>
                        <td style="padding: 4px 6px;">${it.indicator_text}</td>
                        <td style="padding: 4px 6px; text-align: center; font-weight: bold;">${Math.round(it.score)}</td>
                    </tr>`;
                });
            });

            tableHtml += '</table>';
            document.getElementById('rptTableContainer').innerHTML = tableHtml;

            document.getElementById('modalPrintReport').style.display = 'flex';
        })
        .catch(err => {
            alert('Terjadi kesalahan saat memuat dokumen.');
        });
}

function printReportDocument() {
    const printContents = document.getElementById('printableReportArea').innerHTML;
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Raport Supervisi - SMP An Nahl Islamic School</title>
            <style>
                @page { size: A4 portrait; margin: 15mm; }
                body { font-family: 'Times New Roman', Times, serif; color: #000; margin: 0; padding: 0; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #000; padding: 5px; }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${printContents}
        </body>
        </html>
    `);
    printWindow.document.close();
}


let currentCumulativeTeacherId = 92;

function openCumulativeReportModal(teacherId) {
    if (!teacherId) teacherId = 92;
    currentCumulativeTeacherId = teacherId;
    loadCumulativeReport(teacherId);
}

function loadCumulativeReport(teacherId) {
    currentCumulativeTeacherId = teacherId;
    const sel = document.getElementById('selCumulativeTeacher');
    if (sel) sel.value = teacherId;

    fetch(`reports.php?ajax=get_cumulative_report&teacher_id=${teacherId}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                alert(res.message || 'Data supervisi tidak ditemukan.');
                return;
            }
            const d = res.data;
            const cum = d.cumulative;
            const peer = d.peer;
            const kepsek = d.kepsek;

            // Metadata
            document.getElementById('cumTeacherName').textContent = cum.teacher_name;
            document.getElementById('cumPrincipal').textContent = cum.principal_name;
            document.getElementById('cumSubject').textContent = `${cum.subject_name} (${cum.class_name})`;
            document.getElementById('cumPeerObserver').textContent = cum.peer_observer_name;
            document.getElementById('cumDate').textContent = cum.observation_date;
            document.getElementById('cumAcademicYear').textContent = cum.academic_year;

            // Table
            document.getElementById('cumPeerScore').textContent = peer ? Math.round(peer.score) : '-';
            document.getElementById('cumPeerMax').textContent = peer ? Math.round(peer.max_score) : '88';
            document.getElementById('cumPeerPct').textContent = peer ? Number(peer.percentage).toFixed(2) + '%' : '-';
            document.getElementById('cumPeerPred').textContent = peer ? peer.predicate : 'Belum Ada';

            document.getElementById('cumKepsekScore').textContent = kepsek ? Math.round(kepsek.score) : '-';
            document.getElementById('cumKepsekMax').textContent = kepsek ? Math.round(kepsek.max_score) : '96';
            document.getElementById('cumKepsekPct').textContent = kepsek ? Number(kepsek.percentage).toFixed(2) + '%' : '-';
            document.getElementById('cumKepsekPred').textContent = kepsek ? kepsek.predicate : 'Belum Ada';

            document.getElementById('cumTotalMax').textContent = Math.round(cum.max_score);
            document.getElementById('cumAvgPct').textContent = Number(cum.avg_percentage).toFixed(1) + '%';
            document.getElementById('cumAvgPredBadge').textContent = cum.predicate;

            // Components
            let compHtml = '';
            d.components.forEach(c => {
                const pct = Number(c.percentage).toFixed(1);
                const widthPct = Math.max(4, Math.min(100, c.percentage));
                compHtml += `
                    <div style="display: flex; align-items: center; margin-bottom: 5px; font-size: 10px;">
                        <div style="width: 28%; font-weight: bold; color: #000;">${c.name}</div>
                        <div style="flex: 1; height: 14px; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 9999px; overflow: hidden; margin: 0 10px; position: relative;">
                            <div style="width: ${widthPct}%; height: 100%; background: ${c.color}; border-radius: 9999px; display: flex; align-items: center; justify-content: flex-end; padding-right: 6px; color: #fff; font-weight: 800; font-size: 9px; min-width: 24px;">
                                ${pct}%
                            </div>
                        </div>
                        <div style="width: 55px; text-align: right; font-weight: bold; color: #000;">${Math.round(c.score)}/${Math.round(c.max_score)}</div>
                    </div>
                `;
            });
            document.getElementById('cumComponentsContainer').innerHTML = compHtml;

            // Notes
            const notes = d.notes;
            let goodHtml = '';
            if (notes.good && notes.good.length > 0) {
                notes.good.forEach(g => { goodHtml += `<div style="margin: 2px 0 2px 14px; color: #1F2937;">• ${escapeHtml(g)}</div>`; });
            } else {
                goodHtml = '<div style="margin: 2px 0 2px 14px; color: #6B7280; font-style: italic;">- Belum ada catatan khusus.</div>';
            }
            document.getElementById('cumNotesGood').innerHTML = goodHtml;

            let growthHtml = '';
            if (notes.growth && notes.growth.length > 0) {
                notes.growth.forEach(g => { growthHtml += `<div style="margin: 2px 0 2px 14px; color: #1F2937;">• ${escapeHtml(g)}</div>`; });
            } else {
                growthHtml = '<div style="margin: 2px 0 2px 14px; color: #6B7280; font-style: italic;">- Pertahankan capaian positif yang sudah berjalan.</div>';
            }
            document.getElementById('cumNotesGrowth').innerHTML = growthHtml;

            let recomHtml = '';
            if (notes.recommendations && notes.recommendations.length > 0) {
                notes.recommendations.forEach(r => { recomHtml += `<div style="margin: 2px 0 2px 14px; color: #1F2937;">• ${escapeHtml(r)}</div>`; });
            } else {
                recomHtml = '<div style="margin: 2px 0 2px 14px; color: #6B7280; font-style: italic;">- Diharapkan terus konsisten dalam penguatan pembelajaran bermakna.</div>';
            }
            document.getElementById('cumNotesRecom').innerHTML = recomHtml;

            // Signatures
            document.getElementById('cumSignTeacher').textContent = cum.teacher_name;
            document.getElementById('cumSignPeerObserver').textContent = cum.peer_observer_name;
            document.getElementById('cumSignPrincipal').textContent = cum.principal_name;

            document.getElementById('modalPrintCumulativeReport').style.display = 'flex';
        })
        .catch(err => {
            alert('Gagal memuat rangkuman akumulasi supervisi.');
        });
}

function printCumulativeReportDocument() {
    window.open(`reports.php?print_cumulative=1&autoprint=1&teacher_id=${currentCumulativeTeacherId}`, '_blank');
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}


function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

;
