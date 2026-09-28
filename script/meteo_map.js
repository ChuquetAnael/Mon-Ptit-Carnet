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

// LE CŒUR DU SYSTÈME : La récupération météo historique
function fetchWeather(lat, lng) {
    let latInput = document.getElementById('input_lat');
    let lngInput = document.getElementById('input_lng');
    latInput.value = lat.toFixed(6); 
    lngInput.value = lng.toFixed(6);

    // 1. On lit la date exacte renseignée dans le formulaire
    let datetimeStr = document.querySelector('input[name="date_debut"]').value;
    if(!datetimeStr) return; // Sécurité si le champ est vide

    // Formatage pour l'API : YYYY-MM-DD
    let dateYMD = datetimeStr.split('T')[0];
    // Formatage de l'heure cible pour la recherche : YYYY-MM-DDTHH:00
    let targetHour = datetimeStr.substring(0, 13) + ":00"; 

    // 2. On détermine si on a besoin des archives (si la date a plus de 5 jours)
    let sessionDate = new Date(datetimeStr);
    let today = new Date();
    let diffDays = (today - sessionDate) / (1000 * 60 * 60 * 24);
    
    let baseUrl = (diffDays > 5) 
        ? "https://archive-api.open-meteo.com/v1/archive" 
        : "https://api.open-meteo.com/v1/forecast";

    // 3. Appel de l'API avec les bonnes dates et heures
    let url = `${baseUrl}?latitude=${lat}&longitude=${lng}&start_date=${dateYMD}&end_date=${dateYMD}&hourly=temperature_2m,surface_pressure,wind_speed_10m,wind_direction_10m,weathercode`;

    fetch(url)
    .then(response => response.json())
    .then(data => {
        if(data.hourly && data.hourly.time) {
            // On cherche l'index qui correspond exactement à l'heure de la session
            let idx = data.hourly.time.findIndex(t => t.startsWith(targetHour));
            if(idx === -1) idx = 12; // Si l'heure exacte n'est pas trouvée, on prend midi par défaut

            let code = data.hourly.weathercode[idx];
            let dir = data.hourly.wind_direction_10m[idx];

            let skyText = getWeatherCodeStr(code);
            let windDirText = getWindDirectionStr(dir);

            // Affichage de la carte
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



// ==========================================
// INITIALISATION DE LA CARTE
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

    // Placement initial du marqueur si EXIF
    if (!isNaN(initLat) && initLat !== 0) {
        marker = L.marker([initLat, initLng], {draggable: true}).addTo(map);
        fetchWeather(initLat, initLng);
        marker.on('dragend', function() { fetchWeather(marker.getLatLng().lat, marker.getLatLng().lng); });
    }

    // Déplacement au clic
    map.on('click', function(e) {
        if (marker) marker.setLatLng(e.latlng);
        else {
            marker = L.marker(e.latlng, {draggable: true}).addTo(map);
            marker.on('dragend', function() { fetchWeather(marker.getLatLng().lat, marker.getLatLng().lng); });
        }
        fetchWeather(e.latlng.lat, e.latlng.lng);
    });

    // NOUVEAUTÉ : Si on modifie la date/heure à la main, on recharge la météo !
    document.querySelector('input[name="date_debut"]').addEventListener('change', function() {
        let currentLat = parseFloat(latInput.value);
        let currentLng = parseFloat(lngInput.value);
        if(!isNaN(currentLat) && currentLat !== 0) {
            fetchWeather(currentLat, currentLng);
        }
    });
});

// Les autres fonctions de base pour toggle l'interface (ne change pas)
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