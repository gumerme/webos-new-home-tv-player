// Copie para defaults.js (fica fora do git) e preencha. Valores semeados no localStorage só onde a
// chave ainda não existe: rodando como com.webos.app.home a origem é outra que a do modo Dev
// (org.webos.freemytv), então a Home começa com o armazenamento vazio.
window.FMTV_DEFAULTS = {
    fmtv_tmdb_key: '',            // token de leitura do TMDB (opcional: com homeserver a Home não usa)
    fmtv_ip_locate: 'on',
    fmtv_clock_format: '24h',
    fmtv_lang: 'pt'
};

// Endereço(s) do homeserver (opcional). Sem isto a Home tenta http://homeserver:8080.
// window.FMTV_HOMESERVER = ['http://192.168.1.10:8080'];
