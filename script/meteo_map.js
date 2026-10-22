// Utilitaires de conversion Météo
function getWeatherCodeStr(code) {
    if(code === 0) return 'Soleil dégagé';
    if(code > 0 && code <= 3) return 'Nuageux';
    if(code === 45 || code === 48) return 'Brouillard';
    if(code >= 51 && code <= 67) return 'Pluie';
    if(code >= 71 && code <= 77) return 'Neige';
    if(code >= 80 && code <= 82) return 'Averses';
    if(code >= 95) return 'Orage';
    return 'Inconnu';
}

function getWindDirectionStr(degree) {
    if (degree === null || degree === undefined) return '';
    if (degree > 337.5 || degree <= 22.5) return 'N';
    if (degree > 22.5 && degree <= 67.5) return 'NE';
    if (degree > 67.5 && degree <= 112.5) return 'E';
    if (degree > 112.5 && degree <= 157.5) return 'SE';
    if (degree > 157.5 && degree <= 202.5) return 'S';
    if (degree > 202.5 && degree <= 247.5) return 'SO';
    if (degree > 247.5 && degree <= 292.5) return 'O';
    if (degree > 292.5 && degree <= 337.5) return 'NO';
    return '';
}

function escapeHtmlCarte(valeur) {
    return String(valeur ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Fonction utilitaire pour tout rafraîchir d'un coup (Météo + Marée + Débit + Réserves)
function refreshAllConditions(lat, lng) {
    fetchWeather(lat, lng);
    verifierReserves(lat, lng);

    let toggleMaree = document.getElementById('toggle-maree');
    let datetimeStr = document.querySelector('input[name="date_debut"]').value;

    if (toggleMaree && toggleMaree.checked && datetimeStr) {
        let dateYMD = datetimeStr.split('T')[0];
        fetchTide(lat, lng, dateYMD);
    } else if (datetimeStr && !spotEstEnMer()) {
        fetchDebit(lat, lng, datetimeStr);
    }
}

// ------------------------------------------------------------------
// Milieu du spot choisi (données du serveur : window.MPC_SPOTS)
// ------------------------------------------------------------------
function typeSpotChoisi() {
    const select = document.getElementById('spot-select');
    if (!select) return null;
    if (select.value === 'new') return parseInt(document.getElementById('nouveau-type-spot')?.value, 10) || null;
    const info = (window.MPC_SPOTS || {})[select.value];
    return info ? info.type : null;
}
function spotEstEnMer() {
    const t = typeSpotChoisi();
    return t !== null && t >= 10; // 10 à 18 : milieux marins
}
function majPanneauCategorie() {
    const panel = document.getElementById('categorie-panel');
    if (!panel) return;
    const t = typeSpotChoisi();
    panel.hidden = !(t !== null && t <= 8);
    const select = document.getElementById('spot-select');
    const info = (window.MPC_SPOTS || {})[select.value];
    const cat = document.getElementById('categorie-piscicole');
    if (cat && select.value !== 'new') cat.value = info && info.categorie ? String(info.categorie) : '';
}

// ------------------------------------------------------------------
// Zones de réserve : polygones publics fournis par le serveur (window.MPC_RESERVES)
// ------------------------------------------------------------------
function pointDansAnneau(lat, lng, anneau) {
    let dedans = false;
    for (let i = 0, j = anneau.length - 1; i < anneau.length; j = i++) {
        const xi = anneau[i][0], yi = anneau[i][1], xj = anneau[j][0], yj = anneau[j][1];
        if (((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / ((yj - yi) || 1e-12) + xi)) dedans = !dedans;
    }
    return dedans;
}
function pointDansGeometrie(lat, lng, g) {
    if (!g) return false;
    const polygones = g.type === 'Polygon' ? [g.coordinates] : (g.type === 'MultiPolygon' ? g.coordinates : []);
    return polygones.some(anneaux => anneaux.length && pointDansAnneau(lat, lng, anneaux[0])
        && !anneaux.slice(1).some(trou => pointDansAnneau(lat, lng, trou)));
}
function verifierReserves(lat, lng) {
    const zone = document.getElementById('alerte-reserve');
    if (!zone) return;
    const dedans = (window.MPC_RESERVES || []).filter(r => pointDansGeometrie(lat, lng, r.geometrie));
    zone.hidden = dedans.length === 0;
    zone.innerHTML = dedans.map(r => `<span class="material-symbols-rounded" aria-hidden="true">block</span>
        <div><strong>Zone de réserve : ${escapeHtmlCarte(r.nom)}</strong><br>${escapeHtmlCarte(r.regle)}</div>`).join('<hr>');
    if (dedans.length && navigator.vibrate) navigator.vibrate([120, 60, 120]);
}

// ------------------------------------------------------------------
// Débit de la rivière au début de la session (Hub'eau) + alerte de montée des eaux
// ------------------------------------------------------------------
let debitAbort = null;
function fetchDebit(lat, lng, datetimeStr) {
    if (debitAbort) debitAbort.abort();
    debitAbort = new AbortController();
    ['input_debit', 'input_debit_ratio', 'input_station', 'input_cours_eau'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const badge = document.getElementById('ui_debit_badge');
    if (badge) badge.hidden = true;

    fetch(`fetch_riviere.php?action=session&lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&date=${encodeURIComponent(datetimeStr)}`, { signal: debitAbort.signal })
        .then(r => r.json())
        .then(d => {
            if (d.error || d.debit === undefined) return;
            document.getElementById('input_debit').value = d.debit;
            document.getElementById('input_debit_ratio').value = d.ratio ?? '';
            document.getElementById('input_station').value = d.station;
            document.getElementById('input_cours_eau').value = d.cours_eau || '';
            if (badge) {
                const ratio = d.ratio ? ` · ×${String(d.ratio).replace('.', ',')} la normale` : '';
                document.getElementById('ui_debit').textContent = `${String(d.debit).replace('.', ',')} m³/s${ratio}`;
                badge.hidden = false;
                document.getElementById('weather-card').style.display = 'block';
            }
            afficherAlerteCrue(d.alerte);
        })
        .catch(() => {});
}
function afficherAlerteCrue(a) {
    const zone = document.getElementById('alerte-crue');
    if (!zone) return;
    if (!a || !a.niveau) { zone.hidden = true; return; }
    zone.className = 'alerte-terrain mb-3 ' + (a.niveau >= 2 ? 'alerte-danger' : 'alerte-warning');
    zone.innerHTML = `<span class="material-symbols-rounded" aria-hidden="true">flood</span>
        <div><strong>${escapeHtmlCarte(a.titre)}</strong><br>${escapeHtmlCarte(a.message)}</div>`;
    zone.hidden = false;
    if (a.niveau >= 2 && navigator.vibrate) navigator.vibrate([300, 100, 300, 100, 300]);
}

// LE CŒUR DU SYSTÈME : La récupération météo historique
function fetchWeather(lat, lng) {
    let latInput = document.getElementById('input_lat');
    let lngInput = document.getElementById('input_lng');
    latInput.value = lat.toFixed(6); 
    lngInput.value = lng.toFixed(6);

    let datetimeStr = document.querySelector('input[name="date_debut"]').value;
    if(!datetimeStr) return;

    let dateYMD = datetimeStr.split('T')[0];
    let targetHour = datetimeStr.substring(0, 13) + ":00";
    let veille = new Date(dateYMD + 'T12:00:00');
    veille.setDate(veille.getDate() - 1);
    let dateVeille = veille.toISOString().slice(0, 10);
    
    let sessionDate = new Date(datetimeStr);
    let today = new Date();
    let diffDays = (today - sessionDate) / (1000 * 60 * 60 * 24);
    
    let baseUrl = (diffDays > 5) 
        ? "https://archive-api.open-meteo.com/v1/archive" 
        : "https://api.open-meteo.com/v1/forecast";

    let url = `${baseUrl}?latitude=${lat}&longitude=${lng}&start_date=${dateVeille}&end_date=${dateYMD}&hourly=temperature_2m,pressure_msl,wind_speed_10m,wind_direction_10m,weathercode&timezone=Europe%2FParis`;

    fetch(url)
    .then(response => response.json())
    .then(data => {
        if (data.hourly && Array.isArray(data.hourly.time) && data.hourly.time.length > 0) {
            let idx = data.hourly.time.findIndex(t => t.startsWith(targetHour));
            if (idx === -1) idx = Math.min(36, data.hourly.time.length - 1); // midi du jour de la session
            // L'archive peut renvoyer null pour les heures pas encore consolidées
            if (data.hourly.temperature_2m[idx] === null || data.hourly.temperature_2m[idx] === undefined) return;

            let code = data.hourly.weathercode[idx];
            let dir = data.hourly.wind_direction_10m[idx];

            let skyText = getWeatherCodeStr(code);
            let windDirText = getWindDirectionStr(dir);

            document.getElementById('weather-card').style.display = 'block';
            document.getElementById('ui_ciel').innerText = skyText;
            document.getElementById('input_ciel').value = skyText;
            document.getElementById('ui_temp').innerText = data.hourly.temperature_2m[idx];
            document.getElementById('ui_press').innerText = data.hourly.pressure_msl[idx];
            document.getElementById('ui_wind').innerText = data.hourly.wind_speed_10m[idx];
            document.getElementById('ui_direc').innerText = windDirText ? `(${windDirText})` : '';
            
            document.getElementById('input_temp').value = data.hourly.temperature_2m[idx];
            document.getElementById('input_press').value = data.hourly.pressure_msl[idx];
            document.getElementById('input_wind').value = data.hourly.wind_speed_10m[idx];
            document.getElementById('input_direc').value = windDirText;

            // Tendance barométrique (3 h et 24 h) : un des meilleurs indicateurs d'activité du poisson
            const p = data.hourly.pressure_msl;
            const t3 = (idx >= 3 && p[idx - 3] !== null) ? Math.round((p[idx] - p[idx - 3]) * 10) / 10 : null;
            const t24 = (idx >= 24 && p[idx - 24] !== null) ? Math.round((p[idx] - p[idx - 24]) * 10) / 10 : null;
            document.getElementById('input_tend3h').value = t3 ?? '';
            document.getElementById('input_tend24h').value = t24 ?? '';
            const badge = document.getElementById('ui_tendance_badge');
            if (badge && t3 !== null) {
                const sens = t3 <= -1 ? ['En baisse', 'trending_down'] : (t3 >= 1 ? ['En hausse', 'trending_up'] : ['Stable', 'trending_flat']);
                document.getElementById('ui_tendance').textContent = `${sens[0]} (${t3 > 0 ? '+' : ''}${String(t3).replace('.', ',')} hPa/3 h)`;
                document.getElementById('ui_tendance_icone').textContent = sens[1];
                badge.hidden = false;
            }
        }
    }).catch(error => console.log("Erreur météo historique:", error));
}

// LE NOUVEAU CŒUR MARITIME : Connecté au Proxy api-maree.fr
function fetchTide(lat, lng, dateYMD) {
    // La date de la session est transmise : sinon le serveur renvoyait toujours la marée du jour
    let url = `fetch_maree.php?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&date=${encodeURIComponent(dateYMD)}`;

    fetch(url)
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.log("Erreur Proxy Marée:", data.error);
            return;
        }

        let coef = "";
        let currentState = "N/A";
        
        let extremaList = [];
        if (data.data && Array.isArray(data.data) && data.data.length > 0 && data.data[0].extrema) {
            extremaList = data.data[0].extrema;
        } else if (data.extrema) {
            extremaList = data.extrema;
        }

        let exactExtrema = [];

        if (Array.isArray(extremaList)) {
            extremaList.forEach(pmbm => {
                // 1. Extraction du Coefficient maximum
                let cRaw = pmbm.coef || pmbm.coeff;
                if (cRaw !== undefined && cRaw !== null) {
                    let parsedCoef = parseInt(cRaw);
                    if (parsedCoef > 0 && (coef === "" || parsedCoef > parseInt(coef))) {
                        coef = parsedCoef; 
                    }
                }

                // 2. Extraction des Heures pour l'état de la marée
                let timeStr = pmbm.time || "";
                let timeMatch = String(pmbm.datetime || pmbm.time || pmbm.heure || "").match(/([0-2]\d:[0-5]\d)/);
                if (timeMatch) timeStr = timeMatch[1];

                if (timeStr) {
                    let parts = timeStr.split(':');
                    let timeDec = parseInt(parts[0]) + parseInt(parts[1]) / 60;
                    let typeRaw = String(pmbm.type || "").toUpperCase();
                    let isPM = (typeRaw === 'PM' || typeRaw === 'HIGH' || typeRaw === 'PLEINE_MER');
                    exactExtrema.push({ t: timeDec, isPM: isPM });
                }
            });
        }

        // Remplissage HTML du Champ Coefficient
        let inputCoef = document.querySelector('input[name="coeff_maree"]');
        if (inputCoef && coef !== "") inputCoef.value = coef;

        // 3. Calcul de l'état (Montante/Descendante)
        let datetimeStr = document.querySelector('input[name="date_debut"]').value;
        let targetDate = new Date(datetimeStr);
        let targetTimeDec = targetDate.getHours() + targetDate.getMinutes() / 60;

        exactExtrema.sort((a, b) => a.t - b.t);

        if (exactExtrema.length > 0) {
            if (targetTimeDec <= exactExtrema[0].t) {
                currentState = exactExtrema[0].isPM ? "Montante" : "Descendante";
                if (exactExtrema[0].t - targetTimeDec < 0.75) currentState = exactExtrema[0].isPM ? "Haute" : "Basse";
            } 
            else if (targetTimeDec >= exactExtrema[exactExtrema.length - 1].t) {
                currentState = exactExtrema[exactExtrema.length - 1].isPM ? "Descendante" : "Montante";
                if (targetTimeDec - exactExtrema[exactExtrema.length - 1].t < 0.75) currentState = exactExtrema[exactExtrema.length - 1].isPM ? "Haute" : "Basse";
            } 
            else {
                for (let i = 0; i < exactExtrema.length - 1; i++) {
                    if (targetTimeDec >= exactExtrema[i].t && targetTimeDec <= exactExtrema[i+1].t) {
                        currentState = exactExtrema[i].isPM ? "Descendante" : "Montante";
                        if (targetTimeDec - exactExtrema[i].t < 0.75) currentState = exactExtrema[i].isPM ? "Haute" : "Basse";
                        else if (exactExtrema[i+1].t - targetTimeDec < 0.75) currentState = exactExtrema[i+1].isPM ? "Haute" : "Basse";
                        break;
                    }
                }
            }
        }

        // Remplissage HTML du Champ État Marée
        let selectMaree = document.querySelector('select[name="desc_maree"]');
        if (selectMaree && currentState !== "N/A") selectMaree.value = currentState;

    }).catch(error => console.log("Erreur Proxy Marée:", error));
}


// ==========================================
// INITIALISATION DE LA CARTE ET ÉVÉNEMENTS
// ==========================================
document.addEventListener("DOMContentLoaded", function() {
    // Catégorie piscicole : indépendante de la carte (elle doit marcher même si Leaflet n'a pas pu se charger)
    const spotSelect = document.getElementById('spot-select');
    const typeNouveau = document.getElementById('nouveau-type-spot');
    if (spotSelect) spotSelect.addEventListener('change', majPanneauCategorie);
    if (typeNouveau) typeNouveau.addEventListener('change', majPanneauCategorie);
    majPanneauCategorie();

    // Script chargé uniquement là où il y a une carte : on sort proprement sinon
    if (!document.getElementById('map') || typeof L === 'undefined') return;
    let latInput = document.getElementById('input_lat');
    let lngInput = document.getElementById('input_lng');
    let initLat = parseFloat(latInput.value);
    let initLng = parseFloat(lngInput.value);
    
    let defaultView = (!isNaN(initLat) && initLat !== 0) ? [initLat, initLng] : [46.603354, 1.888334];
    let zoom = (!isNaN(initLat) && initLat !== 0) ? 14 : 5;
    let map = L.map('map').setView(defaultView, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
    (window.MPC_RESERVES || []).forEach(r => {
        if (!r.geometrie) return;
        L.geoJSON(r.geometrie, { style: { color: '#b42318', weight: 2, fillOpacity: 0.15 }, interactive: false }).addTo(map);
    });

    let marker = null;

    if (!isNaN(initLat) && initLat !== 0) {
        marker = L.marker([initLat, initLng], {draggable: true}).addTo(map);
        refreshAllConditions(initLat, initLng);
        
        marker.on('dragend', function() {
            refreshAllConditions(marker.getLatLng().lat, marker.getLatLng().lng);
        });
    }

    map.on('click', function(e) {
        if (marker) {
            marker.setLatLng(e.latlng);
        } else {
            marker = L.marker(e.latlng, {draggable: true}).addTo(map);
            marker.on('dragend', function() { 
                refreshAllConditions(marker.getLatLng().lat, marker.getLatLng().lng); 
            });
        }
        refreshAllConditions(e.latlng.lat, e.latlng.lng);
    });

    // Actualisation Météo + Marée en cas de changement d'heure
    document.querySelector('input[name="date_debut"]').addEventListener('change', function() {
        let currentLat = parseFloat(latInput.value);
        let currentLng = parseFloat(lngInput.value);
        if(!isNaN(currentLat) && currentLat !== 0) {
            refreshAllConditions(currentLat, currentLng);
        }
    });

    // Appel API lors de l'activation de l'interrupteur Mer
    let toggleMaree = document.getElementById('toggle-maree');
    if (toggleMaree) {
        toggleMaree.addEventListener('change', function() {
            if (this.checked) {
                let lat = parseFloat(latInput.value);
                let lng = parseFloat(lngInput.value);
                let datetimeStr = document.querySelector('input[name="date_debut"]').value;
                if(!isNaN(lat) && datetimeStr) {
                    let dateYMD = datetimeStr.split('T')[0];
                    fetchTide(lat, lng, dateYMD);
                }
            }
        });
    }
});

function toggleNewSpot() {
    const select = document.getElementById('spot-select');
    const panel = document.getElementById('new-spot-panel');
    if(document.getElementById('is_new_spot')) {
        document.getElementById('is_new_spot').value = (select.value === 'new') ? '1' : '0';
    }
    if(panel) panel.style.display = (select.value === 'new') ? 'block' : 'none';
}

function toggleCapotBtn() {
    const isCapot = document.getElementById('is_capot').checked;
    const btn = document.getElementById('btn-submit-step2');
    if (isCapot) {
        btn.innerHTML = 'Enregistrer la session <span class="material-symbols-rounded align-middle ms-2">check_circle</span>';
        btn.classList.remove('btn-primary', 'custom-btn-submit');
        btn.classList.add('btn-secondary');
    } else {
        btn.innerHTML = 'Continuer <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-primary', 'custom-btn-submit');
    }
}