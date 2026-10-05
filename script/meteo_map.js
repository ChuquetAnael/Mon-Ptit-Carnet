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

// Fonction utilitaire pour tout rafraîchir d'un coup (Météo + Marée)
function refreshAllConditions(lat, lng) {
    fetchWeather(lat, lng);
    
    let toggleMaree = document.getElementById('toggle-maree');
    let datetimeStr = document.querySelector('input[name="date_debut"]').value;
    
    if (toggleMaree && toggleMaree.checked && datetimeStr) {
        let dateYMD = datetimeStr.split('T')[0];
        fetchTide(lat, lng, dateYMD);
    }
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
    
    let sessionDate = new Date(datetimeStr);
    let today = new Date();
    let diffDays = (today - sessionDate) / (1000 * 60 * 60 * 24);
    
    let baseUrl = (diffDays > 5) 
        ? "https://archive-api.open-meteo.com/v1/archive" 
        : "https://api.open-meteo.com/v1/forecast";

    let url = `${baseUrl}?latitude=${lat}&longitude=${lng}&start_date=${dateYMD}&end_date=${dateYMD}&hourly=temperature_2m,surface_pressure,wind_speed_10m,wind_direction_10m,weathercode`;

    fetch(url)
    .then(response => response.json())
    .then(data => {
        if(data.hourly && data.hourly.time) {
            let idx = data.hourly.time.findIndex(t => t.startsWith(targetHour));
            if(idx === -1) idx = 12;

            let code = data.hourly.weathercode[idx];
            let dir = data.hourly.wind_direction_10m[idx];

            let skyText = getWeatherCodeStr(code);
            let windDirText = getWindDirectionStr(dir);

            document.getElementById('weather-card').style.display = 'block';
            document.getElementById('ui_ciel').innerText = skyText;
            document.getElementById('input_ciel').value = skyText;
            document.getElementById('ui_temp').innerText = data.hourly.temperature_2m[idx];
            document.getElementById('ui_press').innerText = data.hourly.surface_pressure[idx];
            document.getElementById('ui_wind').innerText = data.hourly.wind_speed_10m[idx];
            document.getElementById('ui_direc').innerText = windDirText ? `(${windDirText})` : '';
            
            document.getElementById('input_temp').value = data.hourly.temperature_2m[idx];
            document.getElementById('input_press').value = data.hourly.surface_pressure[idx];
            document.getElementById('input_wind').value = data.hourly.wind_speed_10m[idx];
            document.getElementById('input_direc').value = windDirText;
        }
    }).catch(error => console.log("Erreur météo historique:", error));
}

// LE NOUVEAU CŒUR MARITIME : Connecté au Proxy api-maree.fr
function fetchTide(lat, lng, dateYMD) {
    let url = `fetch_maree.php?lat=${lat}&lng=${lng}`;

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
    let latInput = document.getElementById('input_lat');
    let lngInput = document.getElementById('input_lng');
    let initLat = parseFloat(latInput.value);
    let initLng = parseFloat(lngInput.value);
    
    let defaultView = (!isNaN(initLat) && initLat !== 0) ? [initLat, initLng] : [46.603354, 1.888334];
    let zoom = (!isNaN(initLat) && initLat !== 0) ? 14 : 5;
    let map = L.map('map').setView(defaultView, zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);

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