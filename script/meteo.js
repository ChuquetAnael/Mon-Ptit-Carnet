let tideChartInstance = null;
let riverChartInstance = null;
let riverAbortController = null;

document.addEventListener('DOMContentLoaded', function() {
    const selector = document.getElementById('locationSelector');
    const loader = document.getElementById('loader');
    const content = document.getElementById('weatherContent');
    const forecastContainer = document.getElementById('forecast-container');
    const tideContainer = document.getElementById('tide-container');

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

        fetch(`fetch_riviere.php?lat=${lat}&lng=${lng}`, { signal: riverAbortController.signal })
        .then(res => res.json())
        .then(data => {
            if (fetchId !== currentRiverFetchId) return; 

            if (data.error) {
                riverContainer.innerHTML = `
                <div class="alert bg-white border-0 shadow-sm text-center rounded-4 p-5 mt-3">
                    <span class="material-symbols-rounded text-muted mb-3" style="font-size: 60px;">waves</span>
                    <h5 class="fw-bold text-dark">Information Hydrométrique</h5>
                    <p class="text-muted mb-0">${data.error}</p>
                </div>`;
                return;
            }

            riverContainer.innerHTML = `
            <div class="alert alert-info bg-info bg-opacity-10 border-0 rounded-4 mb-4 d-flex align-items-center">
                <span class="material-symbols-rounded text-info me-3" style="font-size: 28px;">water</span>
                <div>
                    <small class="d-block text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">Station de mesure</small>
                    <span class="fw-bold text-info d-block">${data.station_name}</span>
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

    function fetchConditions(lat, lng, locationName) {
        loader.style.display = 'block';
        content.style.display = 'none';
        forecastContainer.innerHTML = '';
        tideContainer.innerHTML = '';

        fetchRiverData(lat, lng);

        // AJOUT DES VARIABLES ASTRONOMIQUES (is_day, sunrise, sunset)
        const urlWeather = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,surface_pressure,wind_speed_10m,wind_direction_10m,weathercode,is_day&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,wind_direction_10m_dominant,sunrise,sunset&hourly=temperature_2m,precipitation,wind_speed_10m,wind_direction_10m,weathercode&timezone=Europe%2FParis`;
        const urlMareeFr = `fetch_maree.php?lat=${lat}&lng=${lng}`;

        Promise.all([
            fetch(urlWeather).then(res => res.json()),
            fetch(urlMareeFr).then(res => res.json())
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

                document.getElementById('current-press').innerText = Math.round(dataWeather.current.surface_pressure) + ' hPa';
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

            // ==========================================
            // 2. TRAITEMENT DE LA MARÉE
            // ==========================================
            let coef = "--";
            let tideEvents = [];
            let portName = "Port Inconnu";
            let distPort = 0;

            if (!dataMareeFr.error) {
                portName = dataMareeFr.port_name_custom || dataMareeFr.site_name || "Port";
                distPort = dataMareeFr.distance_km || 0;
                
                let extremaList = [];
                if (dataMareeFr.data && Array.isArray(dataMareeFr.data) && dataMareeFr.data.length > 0 && dataMareeFr.data[0].extrema) {
                    extremaList = dataMareeFr.data[0].extrema;
                } else if (dataMareeFr.extrema) {
                    extremaList = dataMareeFr.extrema;
                }

                if (Array.isArray(extremaList)) {
                    extremaList.forEach(pmbm => {
                        let typeRaw = String(pmbm.type || "").toUpperCase();
                        let isPM = (typeRaw === 'PM' || typeRaw === 'HIGH' || typeRaw === 'PLEINE_MER');
                        
                        let timeStr = pmbm.time || "--:--";
                        let timeMatch = String(pmbm.datetime || pmbm.time || pmbm.heure || "").match(/([0-2]\d:[0-5]\d)/);
                        if (timeMatch) timeStr = timeMatch[1];

                        let heightVal = parseFloat(pmbm.height ?? pmbm.hauteur ?? pmbm.valeur ?? pmbm.h) || 0;

                        let cRaw = pmbm.coef ?? pmbm.coeff;
                        if (cRaw !== undefined && cRaw !== null) {
                            let parsedCoef = parseInt(cRaw);
                            if (parsedCoef > 0) {
                                if (coef === "--" || parsedCoef > parseInt(coef)) {
                                    coef = parsedCoef; 
                                }
                            }
                        }

                        tideEvents.push({
                            type: isPM ? 'Pleine Mer' : 'Basse Mer',
                            time: timeStr,
                            height: heightVal,
                            icon: isPM ? 'arrow_upward' : 'arrow_downward',
                            color: isPM ? 'text-primary' : 'text-info'
                        });
                    });
                }
            }

            if (distPort > 10 || tideEvents.length === 0) {
                let msg = distPort > 10
                    ? `Ce spot est situé à <b>${Math.round(distPort)} km</b> du littoral. Les données de marée ne s'appliquent pas ici.` 
                    : `Données maritimes non disponibles pour ce secteur.`;

                tideContainer.innerHTML = `
                <div class="alert bg-white border-0 shadow-sm text-center rounded-4 p-5 mt-3">
                    <span class="material-symbols-rounded text-muted mb-3" style="font-size: 60px;">waves</span>
                    <h5 class="fw-bold text-dark">Eaux intérieures</h5>
                    <p class="text-muted mb-0">${msg}</p>
                </div>`;
                
                loader.style.display = 'none';
                content.style.display = 'block';
                return;
            }

            tideEvents.sort((a, b) => a.time.localeCompare(b.time));

            let exactExtrema = [];
            tideEvents.forEach(ev => {
                let p = ev.time.split(':');
                exactExtrema.push({
                    t: parseInt(p[0]) + parseInt(p[1]) / 60,
                    h: ev.height
                });
            });

            if (exactExtrema.length >= 2) {
                let diffFirst = exactExtrema[1].t - exactExtrema[0].t;
                exactExtrema.unshift({ t: exactExtrema[0].t - diffFirst, h: exactExtrema[1].h });
                
                let diffLast = exactExtrema[exactExtrema.length-1].t - exactExtrema[exactExtrema.length-2].t;
                exactExtrema.push({ t: exactExtrema[exactExtrema.length-1].t + diffLast, h: exactExtrema[exactExtrema.length-2].h });
            }

            let chartLabels = [];
            let chartData = [];

            for (let h = 0; h < 24; h++) {
                for (let m = 0; m < 60; m += 15) {
                    let t = h + m / 60;
                    let timeStr = h.toString().padStart(2, '0') + ':' + m.toString().padStart(2, '0');

                    let e1 = null, e2 = null;
                    for (let i = 0; i < exactExtrema.length - 1; i++) {
                        if (t >= exactExtrema[i].t && t <= exactExtrema[i+1].t) {
                            e1 = exactExtrema[i]; e2 = exactExtrema[i+1]; break;
                        }
                    }

                    if (e1 && e2) {
                        let progress = (t - e1.t) / (e2.t - e1.t);
                        let val = e1.h + (e2.h - e1.h) * (1 - Math.cos(Math.PI * progress)) / 2;
                        chartLabels.push(timeStr);
                        chartData.push(val.toFixed(2));
                    }
                }
            }

            let currentState = "Indisponible";
            let now = new Date();
            let nowT = now.getHours() + now.getMinutes() / 60;

            for (let i = 0; i < exactExtrema.length - 1; i++) {
                if (nowT >= exactExtrema[i].t && nowT <= exactExtrema[i+1].t) {
                    currentState = exactExtrema[i].h < exactExtrema[i+1].h ? "Marée Montante" : "Marée Descendante";

                    if (nowT - exactExtrema[i].t < 0.75) {
                        currentState = exactExtrema[i].h > exactExtrema[i+1].h ? "Haute (Pleine Mer)" : "Basse Mer";
                    } else if (exactExtrema[i+1].t - nowT < 0.75) {
                        currentState = exactExtrema[i+1].h > exactExtrema[i].h ? "Haute (Pleine Mer)" : "Basse Mer";
                    }
                    break;
                }
            }

            let tideHtml = `
            <div class="alert alert-primary bg-primary bg-opacity-10 border-0 rounded-4 mb-4 d-flex align-items-center">
                <span class="material-symbols-rounded text-primary me-3" style="font-size: 28px;">anchor</span>
                <div>
                    <small class="d-block text-muted fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">Port de référence</small>
                    <span class="fw-bold text-primary d-block">${portName}</span>
                    <small class="text-secondary" style="font-size: 0.75rem;">Situé à ${distPort} km</small>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100 text-center bg-white border-bottom border-4 border-primary">
                        <span class="material-symbols-rounded text-primary mb-1" style="font-size: 32px;">waves</span>
                        <h6 class="fw-bold text-dark mb-0" style="font-size: 0.85rem;">État actuel</h6>
                        <span class="text-muted fw-semibold" style="font-size: 0.8rem;">${currentState}</span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100 text-center bg-white border-bottom border-4 border-info">
                        <span class="material-symbols-rounded text-info mb-1" style="font-size: 32px;">speed</span>
                        <h6 class="fw-bold text-dark mb-0" style="font-size: 0.85rem;">Coefficient</h6>
                        <span class="text-dark fw-bold fs-5">${coef}</span>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 bg-white p-3 mb-4">
                <h6 class="fw-bold text-secondary text-uppercase mb-3 small"><span class="material-symbols-rounded align-middle me-2 fs-5">show_chart</span> Évolution du niveau de l'eau</h6>
                <div style="height: 180px; width: 100%;">
                    <canvas id="tideChart"></canvas>
                </div>
            </div>

            <h6 class="fw-bold text-secondary text-uppercase mb-3"><span class="material-symbols-rounded align-middle me-2">schedule</span> Horaires officiels du jour</h6>
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
                <ul class="list-group list-group-flush">
            `;

            tideEvents.forEach(ev => {
                tideHtml += `
                <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                    <div class="d-flex align-items-center">
                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                            <span class="material-symbols-rounded ${ev.color}">${ev.icon}</span>
                        </div>
                        <span class="fw-bold text-dark">${ev.type}</span>
                    </div>
                    <div class="text-end">
                        <span class="fw-bold text-dark fs-5 d-block">${ev.time}</span>
                        <small class="text-muted fw-semibold">${ev.height.toFixed(2)}m</small>
                    </div>
                </li>`;
            });
            
            tideHtml += `</ul></div>`;
            tideContainer.innerHTML = tideHtml;

            if (chartData.length > 0) {
                if (tideChartInstance) tideChartInstance.destroy();
                
                const ctx = document.getElementById('tideChart').getContext('2d');
                Chart.defaults.font.family = "'Poppins', sans-serif";
                
                tideChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Hauteur (m)',
                            data: chartData,
                            borderColor: '#0dcaf0',
                            backgroundColor: 'rgba(13, 202, 240, 0.25)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0, 
                            pointHitRadius: 15
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { 
                                grid: { display: false }, 
                                ticks: { maxTicksLimit: 6, color: '#6c757d' } 
                            },
                            y: { 
                                display: false, 
                                min: Math.min(...chartData) - 0.5, 
                                max: Math.max(...chartData) + 0.5 
                            }
                        }
                    }
                });
            }

            loader.style.display = 'none';
            content.style.display = 'block';

        })
        .catch(error => {
            console.log(error);
            loader.innerHTML = '<div class="alert alert-danger rounded-4 m-3">Erreur lors de la récupération des données météo. Vérifiez votre connexion.</div>';
        });
    }

    function loadCurrentGPS() {
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