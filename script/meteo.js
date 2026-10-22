let tideChartInstance = null;
let riverChartInstance = null;
let riverAbortController = null;

/** Les textes renvoyés par les APIs externes sont échappés avant insertion dans le HTML. */
function escapeHtml(valeur) {
    return String(valeur ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

document.addEventListener('DOMContentLoaded', function() {
    const selector = document.getElementById('locationSelector');
    const loader = document.getElementById('loader');
    const content = document.getElementById('weatherContent');
    const forecastContainer = document.getElementById('forecast-container');
    const tideContainer = document.getElementById('tide-container');
    const loaderHtmlInitial = loader.innerHTML; // pour réafficher le spinner après une erreur

    const vueDemandee = new URLSearchParams(location.search).get('vue');
    if (['meteo', 'maree', 'riviere'].includes(vueDemandee)) {
        const radio = document.querySelector(`input[name="viewToggle"][value="${vueDemandee}"]`);
        if (radio) radio.checked = true;
        ['meteo', 'maree', 'riviere'].forEach(v => document.getElementById('section-' + v).style.display = v === vueDemandee ? 'block' : 'none');
    }
    document.querySelectorAll('input[name="viewToggle"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('section-meteo').style.display = (this.value === 'meteo') ? 'block' : 'none';
            document.getElementById('section-maree').style.display = (this.value === 'maree') ? 'block' : 'none';
            document.getElementById('section-riviere').style.display = (this.value === 'riviere') ? 'block' : 'none';
        });
    });

    // LA NOUVELLE FONCTION METEO QUI GÈRE LA NUIT !
    function getWeatherDetails(code, isNight = false) {
        if(code === 0) return { text: isNight ? 'Nuit claire' : 'Soleil', icon: isNight ? 'clear_night' : 'clear_day', color: isNight ? 'text-primary' : 'text-warning' };
        if(code > 0 && code <= 3) return { text: 'Nuageux', icon: isNight ? 'partly_cloudy_night' : 'partly_cloudy_day', color: 'text-secondary' };
        if(code === 45 || code === 48) return { text: 'Brouillard', icon: 'foggy', color: 'text-secondary' };
        if(code >= 51 && code <= 67) return { text: 'Pluie', icon: 'rainy', color: 'text-info' };
        if(code >= 71 && code <= 77) return { text: 'Neige', icon: 'weather_snowy', color: 'text-info' };
        if(code >= 80 && code <= 82) return { text: 'Averses', icon: 'rainy', color: 'text-primary' };
        if(code >= 95) return { text: 'Orage', icon: 'thunderstorm', color: 'text-danger' };
        return { text: 'Inconnu', icon: 'cloud', color: 'text-muted' };
    }

    function getWindDirectionStr(degree) {
        if (degree === null || degree === undefined) return '';
        const dirs = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
        return dirs[Math.round(degree / 45) % 8];
    }

    function getDayName(dateString, index) {
        if (index === 0) return "Aujourd'hui";
        if (index === 1) return "Demain";
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { weekday: 'long' }).charAt(0).toUpperCase() + date.toLocaleDateString('fr-FR', { weekday: 'long' }).slice(1);
    }

    let currentRiverFetchId = 0;

    function fetchRiverData(lat, lng) {
        const riverContainer = document.getElementById('river-container');
        riverContainer.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="text-muted mt-2">Calcul des moyennes mensuelles du bassin...</p></div>';

        if (riverAbortController) {
            riverAbortController.abort();
        }
        riverAbortController = new AbortController();

        let fetchId = ++currentRiverFetchId;

        fetch(`fetch_riviere.php?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`, { signal: riverAbortController.signal })
        .then(res => res.json())
        .then(data => {
            if (fetchId !== currentRiverFetchId) return; 

            if (data.error) {
                riverContainer.innerHTML = `
                <div class="alert bg-white border-0 shadow-sm text-center rounded-4 p-5 mt-3">
                    <span class="material-symbols-rounded text-muted mb-3" style="font-size: 60px;">waves</span>
                    <h5 class="fw-bold text-dark">Information Hydrométrique</h5>
                    <p class="text-muted mb-0">${escapeHtml(data.error)}</p>
                </div>`;
                return;
            }

            riverContainer.innerHTML = blocVigilance(data) + `
            <div class="alert alert-info bg-info bg-opacity-10 border-0 rounded-4 mb-4 d-flex align-items-center">
                <span class="material-symbols-rounded text-info me-3" style="font-size: 28px;">water</span>
                <div>
                    <small class="d-block text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">Station de mesure</small>
                    <span class="fw-bold text-info d-block">${escapeHtml(data.station_name)}</span>
                    <small class="text-secondary" style="font-size: 0.75rem;">Située à ${data.distance} km</small>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100 text-center bg-white border-bottom border-4 border-info">
                        <span class="material-symbols-rounded ${data.etat_color} mb-1" style="font-size: 32px;">waves</span>
                        <h6 class="fw-bold text-dark mb-0" style="font-size: 0.85rem;">${data.mesure_label}</h6>
                        <span class="text-dark fw-bold fs-5">${data.current_flow} ${data.mesure_unit}</span>
                        <span class="badge bg-light ${data.etat_color} mt-1 fw-bold text-wrap" style="font-size: 0.7rem; border: 1px solid currentColor;">${data.etat_riviere}</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100 text-center bg-white border-bottom border-4 border-${data.trend_color.split('-')[1]}">
                        <span class="material-symbols-rounded ${data.trend_color} mb-1" style="font-size: 32px;">${data.trend_icon}</span>
                        <h6 class="fw-bold text-dark mb-0" style="font-size: 0.85rem;">Évolution (24h)</h6>
                        <span class="${data.trend_color} fw-bold fs-5">${data.trend_text}</span>
                        <small class="d-block text-muted mt-2" style="font-size: 0.65rem;">Moy. Saison : ${data.avg_month} ${data.mesure_unit}</small>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 bg-white p-3 mb-4">
                <h6 class="fw-bold text-secondary text-uppercase mb-3 small"><span class="material-symbols-rounded align-middle me-2 fs-5">show_chart</span> Historique sur 1 Mois</h6>
                <div style="height: 180px; width: 100%;">
                    <canvas id="riverChart"></canvas>
                </div>
            </div>
            `;

            brancherSurveillance(data);
            if (riverChartInstance) riverChartInstance.destroy();
            const ctx = document.getElementById('riverChart').getContext('2d');
            Chart.defaults.font.family = "'Poppins', sans-serif";
            
            riverChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.chart_labels,
                    datasets: [{
                        label: `${data.mesure_label} (${data.mesure_unit})`,
                        data: data.chart_data,
                        borderColor: '#0dcaf0',
                        backgroundColor: 'rgba(13, 202, 240, 0.15)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 1,
                        pointHitRadius: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 6, color: '#6c757d' } },
                        y: { display: true, grid: { color: 'rgba(0,0,0,0.05)' } }
                    }
                }
            });
        })
        .catch(err => {
            if (err.name === 'AbortError') return; 
            console.error(err);
            riverContainer.innerHTML = '<div class="alert alert-danger rounded-4 m-3">Erreur lors de la récupération de la station.</div>';
        });
    }

    // ------------------------------------------------------------------
    // Vigilance crues : niveau Vigicrues + montée brutale détectée sur les relevés récents
    // ------------------------------------------------------------------
    const COULEURS_VIGI = { vert: '#23704a', jaune: '#9a6a00', orange: '#c2410c', rouge: '#b42318' };
    function blocVigilance(data) {
        const a = data.alerte;
        const code = data.code_station;
        const suivie = window.MPC && MPC.stationSurveillee() && MPC.stationSurveillee().code === code;
        let etat = '';
        if (a) {
            const vigi = a.vigicrues
                ? `<span class="badge rounded-pill" style="background:${COULEURS_VIGI[a.vigicrues.couleur] || '#3f525a'}">Vigicrues : ${escapeHtml(a.vigicrues.couleur)}</span>`
                : '<span class="badge rounded-pill bg-secondary">Vigicrues : tronçon non surveillé</span>';
            const variation = a.variation_1h !== null && a.variation_1h !== undefined
                ? `<small class="d-block mt-1">Sur 1 h : ${a.variation_1h > 0 ? '+' : ''}${a.variation_1h} ${a.grandeur === 'Q' ? '%' : 'cm'}${a.variation_3h !== null ? ` · sur 3 h : ${a.variation_3h > 0 ? '+' : ''}${a.variation_3h} ${a.grandeur === 'Q' ? '%' : 'cm'}` : ''}</small>` : '';
            etat = a.niveau > 0
                ? `<div class="alerte-terrain ${a.niveau >= 2 ? 'alerte-danger' : 'alerte-warning'} mb-3" role="alert">
                       <span class="material-symbols-rounded" aria-hidden="true">flood</span>
                       <div>${escapeHtml(a.titre)}<br><span style="font-weight:400">${escapeHtml(a.message)}</span>${variation}</div></div>`
                : `<p class="mb-2"><span class="material-symbols-rounded align-middle text-success me-1" aria-hidden="true">check_circle</span>Pas de montée anormale détectée sur les derniers relevés.${variation}</p>`;
            etat += `<p class="mb-3">${vigi}</p>`;
        }
        return `<div class="card border-0 shadow-sm rounded-4 p-3 mb-4">
            <p class="fw-bold mb-2">Sécurité au bord de l'eau</p>
            ${etat}
            ${code ? `<button type="button" class="btn ${suivie ? 'btn-outline-primary' : 'btn-primary'} rounded-pill w-100" id="btn-surveiller" data-code="${escapeHtml(code)}" data-nom="${escapeHtml(data.station_name)}">
                <span class="material-symbols-rounded align-middle me-1" aria-hidden="true">${suivie ? 'notifications_off' : 'notifications_active'}</span>${suivie ? 'Arrêter la surveillance' : 'Me prévenir si l\'eau monte'}</button>
                <small class="d-block text-muted mt-2">Vérification toutes les 10 minutes tant que l'application est ouverte : bandeau, vibreur et notification. Ne remplace pas <a href="https://www.vigicrues.gouv.fr/" target="_blank" rel="noopener">Vigicrues</a> ni les consignes officielles.</small>` : ''}
        </div>`;
    }
    function brancherSurveillance(data) {
        const btn = document.getElementById('btn-surveiller');
        if (!btn || !window.MPC) return;
        btn.addEventListener('click', async () => {
            const suivie = MPC.stationSurveillee() && MPC.stationSurveillee().code === btn.dataset.code;
            if (suivie) {
                MPC.arreterSurveillance();
            } else {
                await MPC.demanderNotifications();
                MPC.surveillerStation(btn.dataset.code, btn.dataset.nom);
                if (navigator.vibrate) navigator.vibrate(80);
            }
            document.getElementById('river-container').querySelector('.card').outerHTML = blocVigilance(data);
            brancherSurveillance(data);
        });
    }


    // =====================================================================
    // MARÉES : courbe et horaires d'une journée, sélecteur des 7 prochains jours.
    // Les 7 jours ne sont demandés qu'au premier appui sur un autre jour (un seul appel,
    // mis en cache côté serveur pour tous les membres : l'API marée est limitée).
    // =====================================================================
    const JOURS_COURTS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    let mareeSemaine = null, mareePosition = null, mareePort = null;

    function dateLocaleIso(d) {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function evenementsMaree(extremaList) {
        const evenements = [];
        let coef = null;
        (Array.isArray(extremaList) ? extremaList : []).forEach(pmbm => {
            const typeRaw = String(pmbm.type || '').toUpperCase();
            const isPM = (typeRaw === 'PM' || typeRaw === 'HIGH' || typeRaw === 'PLEINE_MER');
            const m = String(pmbm.datetime || pmbm.time || pmbm.heure || '').match(/([0-2]\d:[0-5]\d)/);
            const c = parseInt(pmbm.coef ?? pmbm.coeff, 10);
            if (c > 0 && (coef === null || c > coef)) coef = c;
            evenements.push({
                type: isPM ? 'Pleine mer' : 'Basse mer', pm: isPM,
                time: m ? m[1] : '--:--',
                height: parseFloat(pmbm.height ?? pmbm.hauteur ?? pmbm.valeur ?? pmbm.h) || 0,
                icon: isPM ? 'arrow_upward' : 'arrow_downward',
                color: isPM ? 'text-primary' : 'text-info',
            });
        });
        evenements.sort((a, b) => a.time.localeCompare(b.time));
        return { evenements, coef };
    }

    function extremaDe(dataMareeFr) {
        if (dataMareeFr.data && Array.isArray(dataMareeFr.data) && dataMareeFr.data.length > 0 && dataMareeFr.data[0].extrema) return dataMareeFr.data[0].extrema;
        return dataMareeFr.extrema || [];
    }

    function preparerMarees(dataMareeFr, lat, lng) {
        mareeSemaine = null;
        mareePosition = { lat, lng };
        if (dataMareeFr.error) {
            tideContainer.innerHTML = `<div class="alert bg-white border-0 shadow-sm text-center rounded-4 p-4 mt-3"><span class="material-symbols-rounded text-muted" style="font-size:48px">waves</span><p class="text-muted mb-0">${escapeHtml(dataMareeFr.error)}</p></div>`;
            return;
        }
        mareePort = { nom: dataMareeFr.port_name_custom || dataMareeFr.site_name || 'Port', distance: dataMareeFr.distance_km || 0 };
        const { evenements } = evenementsMaree(extremaDe(dataMareeFr));
        if (mareePort.distance > 10 || evenements.length === 0) {
            const msg = mareePort.distance > 10
                ? `Ce spot est situé à <b>${Math.round(mareePort.distance)} km</b> du littoral. Les données de marée ne s'appliquent pas ici.`
                : `Données maritimes non disponibles pour ce secteur.`;
            tideContainer.innerHTML = `
                <div class="alert bg-white border-0 shadow-sm text-center rounded-4 p-5 mt-3">
                    <span class="material-symbols-rounded text-muted mb-3" style="font-size: 60px;">waves</span>
                    <h5 class="fw-bold text-dark">Eaux intérieures</h5>
                    <p class="text-muted mb-0">${msg}</p>
                </div>`;
            return;
        }

        const aujourdhui = new Date();
        let puces = '';
        for (let i = 0; i < 7; i++) {
            const d = new Date(aujourdhui.getFullYear(), aujourdhui.getMonth(), aujourdhui.getDate() + i);
            puces += `<button type="button" class="jour-maree${i === 0 ? ' actif' : ''}" data-date="${dateLocaleIso(d)}" aria-pressed="${i === 0}">
                <span>${i === 0 ? "Auj." : JOURS_COURTS[d.getDay()]}</span><strong>${d.getDate()}</strong><small class="coef-jour">${i === 0 ? '' : '·'}</small></button>`;
        }
        tideContainer.innerHTML = `
            <div class="alert alert-primary bg-primary bg-opacity-10 border-0 rounded-4 mb-3 d-flex align-items-center">
                <span class="material-symbols-rounded text-primary me-3" style="font-size: 28px;">anchor</span>
                <div>
                    <small class="d-block text-muted fw-bold" style="font-size: 0.75rem;">Port de référence</small>
                    <span class="fw-bold text-primary d-block">${escapeHtml(mareePort.nom)}</span>
                    <small class="text-secondary" style="font-size: 0.75rem;">Situé à ${escapeHtml(mareePort.distance)} km</small>
                </div>
            </div>
            <div class="jours-maree mb-3" role="group" aria-label="Choisir le jour">${puces}</div>
            <p class="small text-danger mb-2" id="maree-erreur" hidden></p>
            <div id="maree-jour"></div>
            <div id="maree-semaine" class="mt-4"></div>`;
        tideContainer.querySelectorAll('.jour-maree').forEach(b => b.addEventListener('click', () => choisirJourMaree(b.dataset.date)));
        afficherJourMaree(extremaDe(dataMareeFr), dateLocaleIso(aujourdhui), true);
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'btn btn-light rounded-pill w-100 py-3 fw-bold';
        bouton.innerHTML = '<span class="material-symbols-rounded align-middle me-1" aria-hidden="true">date_range</span> Voir les 7 prochains jours';
        bouton.addEventListener('click', () => chargerSemaine().then(ok => { if (ok) bouton.remove(); }));
        document.getElementById('maree-semaine').appendChild(bouton);
    }

    async function chargerSemaine() {
        if (mareeSemaine) return true;
        const err = document.getElementById('maree-erreur');
        try {
            const r = await fetch(`fetch_maree.php?lat=${encodeURIComponent(mareePosition.lat)}&lng=${encodeURIComponent(mareePosition.lng)}&jours=7`, { credentials: 'same-origin' });
            const j = await r.json();
            if (j.error) throw new Error(j.error);
            mareeSemaine = {};
            j.jours.forEach(jour => { mareeSemaine[jour.date] = jour; });
            tideContainer.querySelectorAll('.jour-maree').forEach(b => {
                const jour = mareeSemaine[b.dataset.date];
                b.querySelector('.coef-jour').textContent = jour && jour.coef ? 'coef ' + jour.coef : '';
            });
            afficherSemaine(j.jours);
            err.hidden = true;
            return true;
        } catch (e) {
            err.textContent = 'Prévisions de la semaine indisponibles pour le moment : réessaie plus tard.';
            err.hidden = false;
            return false;
        }
    }

    async function choisirJourMaree(date) {
        tideContainer.querySelectorAll('.jour-maree').forEach(b => { b.classList.toggle('actif', b.dataset.date === date); b.setAttribute('aria-pressed', b.dataset.date === date); });
        if (!(await chargerSemaine())) return;
        const jour = mareeSemaine[date];
        if (!jour || !jour.disponible) {
            document.getElementById('maree-jour').innerHTML = '<p class="text-muted text-center my-4">Horaires indisponibles pour ce jour.</p>';
            return;
        }
        afficherJourMaree(jour.extrema, date, date === dateLocaleIso(new Date()));
    }

    function afficherSemaine(jours) {
        const lignes = jours.map(j => {
            const d = new Date(j.date + 'T12:00:00');
            const { evenements } = evenementsMaree(j.extrema);
            const pm = evenements.filter(e => e.pm).map(e => e.time).join(' · ') || '–';
            const bm = evenements.filter(e => !e.pm).map(e => e.time).join(' · ') || '–';
            return `<tr data-date="${j.date}" tabindex="0">
                <th scope="row">${JOURS_COURTS[d.getDay()]} ${d.getDate()}</th>
                <td><span class="coef-badge${j.coef >= 95 ? ' fort' : (j.coef && j.coef < 45 ? ' faible' : '')}">${j.coef || '–'}</span></td>
                <td>${pm}</td><td>${bm}</td></tr>`;
        }).join('');
        document.getElementById('maree-semaine').innerHTML = `
            <h6 class="fw-bold text-secondary mb-2"><span class="material-symbols-rounded align-middle me-2" aria-hidden="true">date_range</span>Les 7 prochains jours</h6>
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
                <table class="table table-sm mb-0 table-semaine-maree">
                    <thead><tr><th scope="col">Jour</th><th scope="col">Coef</th><th scope="col">Pleines mers</th><th scope="col">Basses mers</th></tr></thead>
                    <tbody>${lignes}</tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">Touche un jour pour voir sa courbe. Vives-eaux à partir de 95, mortes-eaux sous 45.</p>`;
        document.querySelectorAll('.table-semaine-maree tbody tr').forEach(tr => {
            const aller = () => { choisirJourMaree(tr.dataset.date); document.querySelector('.jours-maree').scrollIntoView({ behavior: 'smooth', block: 'start' }); };
            tr.addEventListener('click', aller);
            tr.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); aller(); } });
        });
    }

    function afficherJourMaree(extremaList, dateIso, estAujourdhui) {
        const { evenements: tideEvents, coef } = evenementsMaree(extremaList);
        const zone = document.getElementById('maree-jour');
        if (!tideEvents.length) { zone.innerHTML = '<p class="text-muted text-center my-4">Horaires indisponibles pour ce jour.</p>'; return; }

        const exactExtrema = tideEvents.map(ev => { const p = ev.time.split(':'); return { t: parseInt(p[0], 10) + parseInt(p[1], 10) / 60, h: ev.height }; });
        if (exactExtrema.length >= 2) {
            const diffFirst = exactExtrema[1].t - exactExtrema[0].t;
            exactExtrema.unshift({ t: exactExtrema[0].t - diffFirst, h: exactExtrema[1].h });
            const diffLast = exactExtrema[exactExtrema.length - 1].t - exactExtrema[exactExtrema.length - 2].t;
            exactExtrema.push({ t: exactExtrema[exactExtrema.length - 1].t + diffLast, h: exactExtrema[exactExtrema.length - 2].h });
        }
        const chartLabels = [], chartData = [];
        for (let h = 0; h < 24; h++) {
            for (let m = 0; m < 60; m += 15) {
                const t = h + m / 60;
                let e1 = null, e2 = null;
                for (let i = 0; i < exactExtrema.length - 1; i++) {
                    if (t >= exactExtrema[i].t && t <= exactExtrema[i + 1].t) { e1 = exactExtrema[i]; e2 = exactExtrema[i + 1]; break; }
                }
                if (e1 && e2) {
                    const progress = (t - e1.t) / (e2.t - e1.t);
                    chartLabels.push(String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0'));
                    chartData.push((e1.h + (e2.h - e1.h) * (1 - Math.cos(Math.PI * progress)) / 2).toFixed(2));
                }
            }
        }

        let etat = '';
        if (estAujourdhui) {
            const now = new Date(), nowT = now.getHours() + now.getMinutes() / 60;
            etat = 'Indisponible';
            for (let i = 0; i < exactExtrema.length - 1; i++) {
                if (nowT >= exactExtrema[i].t && nowT <= exactExtrema[i + 1].t) {
                    etat = exactExtrema[i].h < exactExtrema[i + 1].h ? 'Marée montante' : 'Marée descendante';
                    if (nowT - exactExtrema[i].t < 0.75) etat = exactExtrema[i].h > exactExtrema[i + 1].h ? 'Haute (pleine mer)' : 'Basse mer';
                    else if (exactExtrema[i + 1].t - nowT < 0.75) etat = exactExtrema[i + 1].h > exactExtrema[i].h ? 'Haute (pleine mer)' : 'Basse mer';
                    break;
                }
            }
        } else {
            const premiere = tideEvents.find(e => e.pm);
            etat = premiere ? 'Pleine mer à ' + premiere.time : '–';
        }
        const d = new Date(dateIso + 'T12:00:00');
        const libelleJour = estAujourdhui ? "du jour" : `du ${JOURS_COURTS[d.getDay()]} ${d.getDate()}`;

        let html = `
            <div class="row g-3 mb-4">
                <div class="col-6">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100 text-center bg-white border-bottom border-4 border-primary">
                        <span class="material-symbols-rounded text-primary mb-1" style="font-size: 32px;">waves</span>
                        <h6 class="fw-bold text-dark mb-0" style="font-size: 0.85rem;">${estAujourdhui ? 'État actuel' : 'Ce jour-là'}</h6>
                        <span class="text-muted fw-semibold" style="font-size: 0.85rem;">${escapeHtml(etat)}</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100 text-center bg-white border-bottom border-4 border-info">
                        <span class="material-symbols-rounded text-info mb-1" style="font-size: 32px;">speed</span>
                        <h6 class="fw-bold text-dark mb-0" style="font-size: 0.85rem;">Coefficient</h6>
                        <span class="text-dark fw-bold fs-5">${coef || '--'}</span>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-4 bg-white p-3 mb-4">
                <h6 class="fw-bold text-secondary mb-3 small"><span class="material-symbols-rounded align-middle me-2 fs-5">show_chart</span> Niveau de l'eau ${libelleJour}</h6>
                <div style="height: 180px; width: 100%;"><canvas id="tideChart"></canvas></div>
            </div>
            <h6 class="fw-bold text-secondary mb-3"><span class="material-symbols-rounded align-middle me-2">schedule</span> Horaires ${libelleJour}</h6>
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden"><ul class="list-group list-group-flush">`;
        tideEvents.forEach(ev => {
            html += `
                <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                    <div class="d-flex align-items-center">
                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                            <span class="material-symbols-rounded ${ev.color}">${ev.icon}</span>
                        </div>
                        <span class="fw-bold text-dark">${ev.type}</span>
                    </div>
                    <div class="text-end">
                        <span class="fw-bold text-dark fs-5 d-block">${ev.time}</span>
                        <small class="text-muted fw-semibold">${ev.height.toFixed(2)} m</small>
                    </div>
                </li>`;
        });
        zone.innerHTML = html + '</ul></div>';

        if (chartData.length > 0 && window.Chart) {
            if (tideChartInstance) tideChartInstance.destroy();
            tideChartInstance = new Chart(document.getElementById('tideChart').getContext('2d'), {
                type: 'line',
                data: { labels: chartLabels, datasets: [{ label: 'Hauteur (m)', data: chartData, borderColor: '#0dcaf0', backgroundColor: 'rgba(13, 202, 240, 0.25)', borderWidth: 3, fill: true, tension: 0.4, pointRadius: 0, pointHitRadius: 15 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                    scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 6, color: '#6c757d' } }, y: { display: false, min: Math.min(...chartData) - 0.5, max: Math.max(...chartData) + 0.5 } },
                },
            });
        }
    }

    function fetchConditions(lat, lng, locationName) {
        loader.innerHTML = loaderHtmlInitial;
        loader.style.display = 'block';
        content.style.display = 'none';
        forecastContainer.innerHTML = '';
        tideContainer.innerHTML = '';

        fetchRiverData(lat, lng);

        // AJOUT DES VARIABLES ASTRONOMIQUES (is_day, sunrise, sunset)
        const urlWeather = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,pressure_msl,wind_speed_10m,wind_direction_10m,weathercode,is_day&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,wind_direction_10m_dominant,sunrise,sunset&hourly=temperature_2m,precipitation,wind_speed_10m,wind_direction_10m,weathercode&timezone=Europe%2FParis`;
        const urlMareeFr = `fetch_maree.php?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`;

        // Une panne du service de marées ne doit pas empêcher l'affichage de la météo
        Promise.all([
            fetch(urlWeather).then(res => res.json()),
            fetch(urlMareeFr).then(res => res.json()).catch(() => ({ error: 'Marée indisponible' }))
        ])
        .then(([dataWeather, dataMareeFr]) => {
            
            // ==========================================
            // 1. TRAITEMENT DE LA MÉTÉO
            // ==========================================
            if(dataWeather.current && dataWeather.daily && dataWeather.hourly) {
                
                // Météo Actuelle avec détection Nuit/Jour
                const isCurrentlyDay = dataWeather.current.is_day === 1;
                const currentDetails = getWeatherDetails(dataWeather.current.weathercode, !isCurrentlyDay);
                const currentWindDir = getWindDirectionStr(dataWeather.current.wind_direction_10m);
                
                document.getElementById('current-city').innerText = locationName;
                document.getElementById('current-temp').innerText = Math.round(dataWeather.current.temperature_2m);
                document.getElementById('current-desc').innerText = currentDetails.text;
                document.getElementById('current-icon').innerText = currentDetails.icon;
                
                // On met à jour la couleur de la carte principale s'il fait nuit
                const mainCard = document.querySelector('.weather-main-card');
                if (!isCurrentlyDay) {
                    mainCard.style.background = 'linear-gradient(135deg, #121c2d 0%, #20314b 100%)';
                } else {
                    mainCard.style.background = 'linear-gradient(135deg, #1e3c72 0%, #2a5298 100%)';
                }

                document.getElementById('current-press').innerText = Math.round(dataWeather.current.pressure_msl) + ' hPa';
                document.getElementById('current-wind').innerText = Math.round(dataWeather.current.wind_speed_10m) + ' km/h ' + (currentWindDir ? `(${currentWindDir})` : '');

                let hTime = dataWeather.hourly.time;
                let hTemp = dataWeather.hourly.temperature_2m;
                let hPrecip = dataWeather.hourly.precipitation;
                let hWind = dataWeather.hourly.wind_speed_10m;
                let hCode = dataWeather.hourly.weathercode;

                for(let i = 0; i < dataWeather.daily.time.length; i++) {
                    let dayDate = dataWeather.daily.time[i];
                    let maxTemp = Math.round(dataWeather.daily.temperature_2m_max[i]);
                    let minTemp = Math.round(dataWeather.daily.temperature_2m_min[i]);
                    let precip = dataWeather.daily.precipitation_sum[i];
                    let windMax = Math.round(dataWeather.daily.wind_speed_10m_max[i]);
                    let windDir = getWindDirectionStr(dataWeather.daily.wind_direction_10m_dominant[i]);
                    let details = getWeatherDetails(dataWeather.daily.weathercode[i], false); // Journée globale = icône de jour
                    let dayName = getDayName(dayDate, i);
                    let collapseId = `collapseDay${i}`;

                    // Heures astronomiques pour ce jour précis
                    let sunriseTime = new Date(dataWeather.daily.sunrise[i]).getTime();
                    let sunsetTime = new Date(dataWeather.daily.sunset[i]).getTime();

                    let hourlyHtml = `<div class="d-flex justify-content-between overflow-auto py-3 px-1 timeline-scroll">`;
                    for(let j = 0; j < hTime.length; j++) {
                        if(hTime[j].startsWith(dayDate)) {
                            let hourStr = hTime[j].substring(11, 16);
                            let hourInt = parseInt(hourStr.substring(0,2));
                            
                            // RESTAURATION : Toutes les 2 heures !
                            if (hourInt % 2 === 0) {
                                let hourTimestamp = new Date(hTime[j]).getTime();
                                let isNightHour = (hourTimestamp < sunriseTime || hourTimestamp > sunsetTime);
                                let hDetails = getWeatherDetails(hCode[j], isNightHour);
                                
                                hourlyHtml += `
                                <div class="text-center d-flex flex-column align-items-center flex-fill" style="min-width: 50px;">
                                    <span class="text-muted fw-bold mb-1" style="font-size: 0.75rem;">${hourStr}</span>
                                    <span class="material-symbols-rounded ${hDetails.color} mb-1" style="font-size: 26px;">${hDetails.icon}</span>
                                    <span class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">${Math.round(hTemp[j])}°</span>
                                    <span class="text-primary d-flex align-items-center" style="font-size: 0.7rem;">
                                        <span class="material-symbols-rounded" style="font-size: 12px; margin-right:2px;">water_drop</span>
                                        ${hPrecip[j] > 0 ? hPrecip[j]+'mm' : '0'}
                                    </span>
                                    <span class="text-secondary d-flex align-items-center mt-1" style="font-size: 0.7rem;">
                                        <span class="material-symbols-rounded" style="font-size: 12px; margin-right:2px;">air</span>
                                        ${Math.round(hWind[j])}
                                    </span>
                                </div>`;
                            }
                        }
                    }
                    hourlyHtml += `</div>`;

                    let html = `
                    <div class="card rounded-4 border-0 shadow-sm forecast-card overflow-hidden" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="false" aria-controls="${collapseId}">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div style="width: 25%;">
                                    <span class="fw-bold text-dark d-block text-truncate" style="font-size: 0.9rem;">${dayName}</span>
                                    <small class="text-muted" style="font-size: 0.7rem;">${dayDate.split('-').reverse().join('/')}</small>
                                </div>
                                <div class="text-center d-flex flex-column align-items-center justify-content-center" style="width: 25%;">
                                    <span class="material-symbols-rounded fs-2 ${details.color}">${details.icon}</span>
                                    <small class="text-primary fw-bold mt-1" style="font-size: 0.7rem;">
                                        ${precip > 0 ? `<span class="material-symbols-rounded align-middle" style="font-size: 14px;">water_drop</span> ${precip}mm` : '<span class="text-muted">Sec</span>'}
                                    </small>
                                </div>
                                <div class="text-end" style="width: 40%;">
                                    <div class="mb-1">
                                        <span class="fw-bold text-danger me-2">${maxTemp}°</span>
                                        <span class="fw-bold text-primary">${minTemp}°</span>
                                    </div>
                                    <small class="text-muted d-flex align-items-center justify-content-end" style="font-size: 0.75rem;">
                                        <span class="material-symbols-rounded me-1" style="font-size: 14px;">air</span> ${windMax} km/h ${windDir}
                                    </small>
                                </div>
                                <div class="text-end" style="width: 10%;">
                                    <span class="material-symbols-rounded text-muted chevron-icon">expand_more</span>
                                </div>
                            </div>
                        </div>
                        <div id="${collapseId}" class="collapse" data-bs-parent="#forecast-container">
                            <div class="card-body bg-light border-top p-2">
                                <h6 class="small fw-bold text-secondary text-center text-uppercase mt-2 mb-1" style="font-size: 0.65rem; letter-spacing: 1px;">Évolution de la journée</h6>
                                ${hourlyHtml}
                            </div>
                        </div>
                    </div>`;
                    forecastContainer.insertAdjacentHTML('beforeend', html);
                }
            }

            // 2. MARÉE : aujourd'hui, puis les 7 prochains jours à la demande
            preparerMarees(dataMareeFr, lat, lng);

            loader.style.display = 'none';
            content.style.display = 'block';

        })
        .catch(error => {
            console.log(error);
            loader.innerHTML = '<div class="alert alert-danger rounded-4 m-3">Erreur lors de la récupération des données météo. Vérifiez votre connexion.</div>';
        });
    }

    function loadCurrentGPS() {
        loader.innerHTML = loaderHtmlInitial;
        loader.style.display = 'block';
        content.style.display = 'none';
        
        if ("geolocation" in navigator) {
            navigator.geolocation.getCurrentPosition(function(position) {
                fetchConditions(position.coords.latitude, position.coords.longitude, "Ma Position (GPS)");
            }, function(error) {
                loader.innerHTML = '<div class="alert alert-warning rounded-4 m-3"><span class="material-symbols-rounded align-middle me-2">location_disabled</span> GPS désactivé ou refusé. Sélectionnez un spot enregistré.</div>';
            });
        } else {
            loader.innerHTML = '<div class="alert alert-danger rounded-4 m-3">GPS non supporté par ce navigateur.</div>';
        }
    }

    selector.addEventListener('change', function() {
        if (this.value === 'gps') {
            loadCurrentGPS();
        } else {
            const selectedOption = this.options[this.selectedIndex];
            const lat = parseFloat(selectedOption.getAttribute('data-lat'));
            const lng = parseFloat(selectedOption.getAttribute('data-lng'));
            const nom = selectedOption.text.replace('🎣 ', '').trim();
            fetchConditions(lat, lng, nom);
        }
    });

    loadCurrentGPS();
});