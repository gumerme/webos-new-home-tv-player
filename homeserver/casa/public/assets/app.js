'use strict';

const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const raiz = document.documentElement;
let redesenhar = null;
let atualizarDashboard = null;

// ------------------------------------------------------------------- tema

// Tema: classe html.tema-escuro, lua/sol na barra superior.
$('#tema')?.addEventListener('click', () => {
  const escuro = raiz.classList.toggle('tema-escuro');
  try { localStorage.setItem('casa_tema', escuro ? 'escuro' : 'claro'); } catch (e) { /* sem armazenamento */ }
  if (redesenhar) redesenhar();
});

// Menu lateral em gaveta no celular
(() => {
  const sb = $('#sidebar'), fundo = $('#sidebar-fundo'), botao = $('#menu-abrir');
  if (!sb || !botao) return;
  const abrir = (sim) => {
    sb.classList.toggle('aberto', sim);
    fundo.hidden = !sim;
    botao.setAttribute('aria-expanded', String(sim));
    if (sim) $('.sb-item', sb)?.focus();
  };
  botao.addEventListener('click', () => abrir(!sb.classList.contains('aberto')));
  fundo.addEventListener('click', () => abrir(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && sb.classList.contains('aberto')) { abrir(false); botao.focus(); } });
})();

// ------------------------------------------------------------- utilidades

const ICONES = (() => { try { return JSON.parse($('#icones')?.textContent || '{}'); } catch { return {}; } })();
const ico = (n) => `<i class="ti ti-${ICONES[n] || 'point'}" aria-hidden="true"></i>`;

function toast(msg, erro = false) {
  const t = $('#toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'mostrar' + (erro ? ' erro' : '');
  clearTimeout(toast.h);
  toast.h = setTimeout(() => { t.className = ''; }, erro ? 6000 : 3200);
}

async function api(metodo, url, corpo) {
  const r = await fetch(url, {
    method: metodo,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
    body: corpo ? JSON.stringify(corpo) : undefined,
  });
  if (r.status === 401) { location.href = '/login?volta=' + encodeURIComponent(location.pathname); throw new Error('sessão expirada'); }
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.erro || (j.resposta && j.resposta.erro) || ('HTTP ' + r.status));
  return j;
}

const fmt = {
  num(v, dec = 1) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: dec, minimumFractionDigits: dec });
  },
  bytes(b, dec = 1) {
    if (b == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; b = Number(b);
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return b.toLocaleString('pt-BR', { maximumFractionDigits: i ? dec : 0 }) + ' ' + u[i];
  },
  taxa(bps) { return bps == null ? '—' : fmt.bytes(bps) + '/s'; },
  real(v) { return v == null ? '—' : 'R$ ' + fmt.num(v, 2); },
  dur(s) {
    s = Math.max(0, Math.round(s));
    const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    if (d) return `${d}d ${h}h`;
    if (h) return `${h}h ${m}min`;
    return `${m}min`;
  },
  /** "há 5 min", "em 3 h" a partir de um timestamp Unix. */
  rel(ts) {
    if (!ts) return '—';
    const dif = Math.round(Date.now() / 1000 - ts);
    const abs = Math.abs(dif);
    let t;
    if (abs < 45) return dif >= 0 ? 'agora' : 'em instantes';
    if (abs < 3600) t = Math.round(abs / 60) + ' min';
    else if (abs < 86400) t = Math.round(abs / 3600) + ' h';
    else t = Math.round(abs / 86400) + (Math.round(abs / 86400) === 1 ? ' dia' : ' dias');
    return dif >= 0 ? 'há ' + t : 'em ' + t;
  },
  hora(ts) { return new Date(ts * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); },
  data(ts) { return new Date(ts * 1000).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); },
};

function ocupado(botao, sim, texto) {
  if (!botao) return;
  if (sim) {
    botao.dataset.textoOriginal ??= botao.innerHTML;
    botao.setAttribute('aria-busy', 'true');
    botao.disabled = true;
    if (texto) botao.textContent = texto;
  } else {
    botao.removeAttribute('aria-busy');
    botao.disabled = false;
    if (botao.dataset.textoOriginal) botao.innerHTML = botao.dataset.textoOriginal;
    delete botao.dataset.textoOriginal;
  }
}

// ----------------------------------------------------- cliques globais

document.addEventListener('click', async (ev) => {
  const b = ev.target.closest('[data-cmd]');
  if (b) {
    const nome = b.closest('[data-nome]')?.dataset.nome || b.dataset.slug;
    const corpo = { acao: b.dataset.cmd };
    if (b.dataset.valor !== undefined) corpo.parametros = { valor: b.dataset.valor === '1' };
    // parâmetro vindo de um campo (ex.: porções do alimentador): data-param="porcoes" data-param-de="#campo"
    if (b.dataset.param && b.dataset.paramDe) corpo.parametros = { [b.dataset.param]: Number($(b.dataset.paramDe)?.value || 1) };
    if (b.dataset.cmd === 'parear') toast(`${nome}: aceite o pedido na tela da TV (até 60 s)…`);
    ocupado(b, true);
    try {
      await api('POST', `/api/dispositivos/${encodeURIComponent(b.dataset.slug)}/comandos`, corpo);
      toast(`${nome}: comando enviado`);
      if (document.body.dataset.pagina === 'aparelhos') setTimeout(() => location.reload(), 700);
      else if (atualizarDashboard) atualizarDashboard();
    } catch (e) {
      toast(`${nome}: ${e.message}`, true);
    } finally {
      ocupado(b, false);
    }
    return;
  }
  const rev = ev.target.closest('[data-revelar]');
  if (rev) {
    const alvo = $(rev.dataset.revelar);
    const aberto = alvo.dataset.aberto === '1';
    alvo.textContent = aberto ? '•'.repeat(24) : alvo.dataset.valor;
    alvo.dataset.aberto = aberto ? '0' : '1';
    const rot = $('span', rev);
    if (rot) rot.textContent = aberto ? 'Mostrar' : 'Ocultar';
    return;
  }
  const cop = ev.target.closest('[data-copiar]');
  if (cop) {
    try { await navigator.clipboard.writeText($(cop.dataset.copiar).dataset.valor); toast('Copiado'); }
    catch { toast('Não deu para copiar: use “Mostrar” e copie à mão.', true); }
    return;
  }
  const ver = ev.target.closest('[data-ver-senha]');
  if (ver) {
    const campo = $(ver.dataset.verSenha);
    const mostrar = campo.type === 'password';
    campo.type = mostrar ? 'text' : 'password';
    ver.setAttribute('aria-pressed', String(mostrar));
    ver.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
    return;
  }
  const alt = ev.target.closest('[data-alternar]');
  if (alt) {
    const alvo = $(alt.dataset.alternar);
    if (alvo) {
      alvo.hidden = !alvo.hidden;
      alt.setAttribute('aria-expanded', String(!alvo.hidden));
    }
    return;
  }

  const filtro = ev.target.closest('[data-filtrar]');
  if (filtro) {
    const grupo = filtro.closest('[data-filtro]');
    $$('[role="radio"]', grupo).forEach((x) => x.setAttribute('aria-checked', String(x === filtro)));
    const valor = filtro.dataset.filtrar;
    $$(grupo.dataset.filtro).forEach((tr) => { tr.hidden = valor !== '' && tr.dataset.status !== valor; });
  }
});

document.addEventListener('submit', (ev) => {
  if (ev.target.dataset.confirmar && !confirm(ev.target.dataset.confirmar)) {
    ev.preventDefault();
    return;
  }
  const b = ev.submitter || $('button[data-carregando]', ev.target);
  if (b && b.dataset.carregando && !b.name) setTimeout(() => ocupado(b, true, b.dataset.carregando), 0);
});

// horas da página de atividade: data completa ao passar o mouse
$$('time[data-ts]').forEach((t) => { t.title = new Date(Number(t.dataset.ts) * 1000).toLocaleString('pt-BR'); });

// ---------------------------------------------------------------- gráficos

/**
 * Linha(s) com área. series: [{nome, cor, pontos: [[t, v], ...]}]
 * opts: {min, max, formato, formatoEixo, dias, titulo, mini}
 */
function grafico(box, series, opts = {}) {
  if (!box) return;
  const W = box.clientWidth || 600, H = box.clientHeight || 180;
  const m = opts.mini ? { e: 0, d: 0, t: 4, b: 4 } : { e: 46, d: 10, t: 10, b: 24 };
  const ns = 'http://www.w3.org/2000/svg';
  box.innerHTML = '';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('aria-hidden', 'true');
  box.append(svg);

  const todos = series.flatMap((s) => s.pontos.filter((p) => p[1] != null));
  if (!todos.length) {
    svg.innerHTML = `<text x="${W / 2}" y="${H / 2 + 4}" text-anchor="middle">sem dados neste período</text>`;
    box.removeAttribute('tabindex');
    return;
  }
  const ts = series[0].pontos.map((p) => p[0]);
  const vs = todos.map((p) => p[1]);
  const t0 = Math.min(...ts), t1 = Math.max(...ts);
  let v0 = opts.min ?? Math.min(...vs), v1 = opts.max ?? Math.max(...vs);
  if (opts.max == null) v1 += (v1 - v0) * 0.15 || Math.abs(v1) * 0.2 || 1;
  if (opts.min == null) v0 = Math.max(0, v0 - (v1 - v0) * 0.15);
  if (v1 === v0) v1 = v0 + 1;
  const x = (t) => m.e + (t1 === t0 ? 0.5 : (t - t0) / (t1 - t0)) * (W - m.e - m.d);
  const y = (v) => H - m.b - (v - v0) / (v1 - v0) * (H - m.t - m.b);
  const fEixo = opts.formatoEixo || opts.formato || ((v) => fmt.num(v, 0));
  const fValor = opts.formato || fEixo;
  const id = box.dataset.grafico;

  let html = '<defs>';
  series.forEach((s, i) => {
    html += `<linearGradient id="g-${id}-${i}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${s.cor}" stop-opacity="${series.length > 1 ? 0.14 : 0.3}"/><stop offset="1" stop-color="${s.cor}" stop-opacity="0"/></linearGradient>`;
  });
  html += '</defs>';
  if (!opts.mini) {
    for (let i = 0; i <= 3; i++) {
      const v = v0 + (v1 - v0) * i / 3, yy = y(v).toFixed(1);
      html += `<line class="grade" x1="${m.e}" x2="${W - m.d}" y1="${yy}" y2="${yy}"/>`;
      html += `<text x="${m.e - 8}" y="${(+yy + 4).toFixed(1)}" text-anchor="end">${fEixo(v)}</text>`;
    }
    const marcas = W < 420 ? 2 : 4;
    for (let i = 0; i <= marcas; i++) {
      const t = t0 + (t1 - t0) * i / marcas;
      const rot = opts.dias ? new Date(t * 1000).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : fmt.hora(t);
      html += `<text x="${x(t).toFixed(1)}" y="${H - 6}" text-anchor="${i === 0 ? 'start' : i === marcas ? 'end' : 'middle'}">${rot}</text>`;
    }
  }
  const passo = ts.length > 1 ? (t1 - t0) / (ts.length - 1) : 0;
  series.forEach((s, i) => {
    const trechos = [];
    let atual = [], tAnt = null;
    for (const [t, v] of s.pontos) {
      if (v == null || (tAnt != null && passo && t - tAnt > passo * 3)) { if (atual.length) trechos.push(atual); atual = []; }
      if (v != null) atual.push([x(t), y(v)]);
      tAnt = t;
    }
    if (atual.length) trechos.push(atual);
    for (const tr of trechos) {
      const linha = tr.map((p) => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
      const base = (H - m.b).toFixed(1);
      html += `<polygon points="${tr[0][0].toFixed(1)},${base} ${linha} ${tr[tr.length - 1][0].toFixed(1)},${base}" fill="url(#g-${id}-${i})"/>`;
      html += `<polyline points="${linha}" fill="none" stroke="${s.cor}" stroke-width="${opts.mini ? 1.6 : 2}" stroke-linejoin="round" stroke-linecap="round"/>`;
    }
  });
  html += `<line class="mira" x1="0" x2="0" y1="${m.t}" y2="${H - m.b}" visibility="hidden"/>`;
  series.forEach((s) => { html += `<circle r="4" fill="${s.cor}" stroke="var(--panel)" stroke-width="2" visibility="hidden"/>`; });
  svg.innerHTML = html;

  const dica = document.createElement('div');
  dica.className = 'dica';
  box.append(dica);
  const mira = $('.mira', svg);
  const pontos = $$('circle', svg);
  const mostrar = (idx) => {
    if (idx == null || idx < 0 || idx >= ts.length) {
      mira.setAttribute('visibility', 'hidden'); pontos.forEach((c) => c.setAttribute('visibility', 'hidden'));
      dica.classList.remove('mostrar'); return;
    }
    const t = ts[idx], xx = x(t);
    mira.setAttribute('x1', xx); mira.setAttribute('x2', xx); mira.setAttribute('visibility', 'visible');
    let linhas = '';
    series.forEach((s, i) => {
      const v = s.pontos[idx]?.[1];
      if (v == null) { pontos[i].setAttribute('visibility', 'hidden'); return; }
      pontos[i].setAttribute('cx', xx); pontos[i].setAttribute('cy', y(v)); pontos[i].setAttribute('visibility', 'visible');
      linhas += `<span><i class="leg" style="background:${s.cor}"></i>${series.length > 1 ? s.nome + ': ' : ''}${fValor(v)}</span>`;
    });
    dica.innerHTML = `<b>${fmt.data(t)}</b>${linhas}`;
    const largura = dica.offsetWidth || 140;
    dica.style.left = Math.min(Math.max(0, xx - largura / 2), W - largura) + 'px';
    dica.style.top = opts.mini ? '-56px' : '-10px';
    dica.classList.add('mostrar');
  };
  const indice = (clientX) => {
    const r = box.getBoundingClientRect();
    const t = t0 + ((clientX - r.left) - m.e) / (W - m.e - m.d) * (t1 - t0);
    let melhor = 0;
    ts.forEach((tt, i) => { if (Math.abs(tt - t) < Math.abs(ts[melhor] - t)) melhor = i; });
    return melhor;
  };
  box.onpointermove = (e) => mostrar(indice(e.clientX));
  box.onpointerleave = () => mostrar(null);
  box.tabIndex = 0;
  let foco = ts.length - 1;
  box.onfocus = () => mostrar(foco);
  box.onblur = () => mostrar(null);
  box.onkeydown = (e) => {
    if (e.key === 'ArrowLeft') foco = Math.max(0, foco - 1);
    else if (e.key === 'ArrowRight') foco = Math.min(ts.length - 1, foco + 1);
    else if (e.key === 'Home') foco = 0;
    else if (e.key === 'End') foco = ts.length - 1;
    else return;
    e.preventDefault();
    mostrar(foco);
  };
  const s0 = series[0].pontos.map((p) => p[1]).filter((v) => v != null);
  box.setAttribute('role', 'img');
  box.setAttribute('aria-label', `${opts.titulo || 'Gráfico'}: último ${fValor(s0[s0.length - 1])}, máximo ${fValor(Math.max(...s0))}, mínimo ${fValor(Math.min(...s0))}. Use as setas para ver cada ponto.`);
}

// ------------------------------------------------------------- dashboard

if (document.body.dataset.pagina === 'dashboard') {
  const cor = (n) => getComputedStyle(raiz).getPropertyValue(n).trim();
  let periodo = 24, pausado = false, ultimo = null, ultimoOk = 0, timer = null;

  const NOMES = {
    'casa-web': 'Painel e API', 'casa-worker': 'Worker dos aparelhos', homebridge: 'Homebridge (Siri)',
    postgres: 'Banco de dados', 'matter-server': 'Matter', tailscale: 'Tailscale',
  };
  const ICONE_TIPO = { comando: 'tv', aparelho: 'tv', sistema: 'servidor', backup: 'arquivo', rede: 'globo', acesso: 'cadeado', usuario: 'usuario', config: 'editar', seguranca: 'escudo' };
  const nivel = (pct, alto = 75, critico = 90) => (pct >= critico ? 'critico' : pct >= alto ? 'alto' : '');

  const kv = (id, linhas) => {
    const dl = $('#' + id);
    if (!dl) return;
    dl.replaceChildren();
    for (const [rotulo, valor, classe] of linhas) {
      const dt = document.createElement('dt'); dt.textContent = rotulo;
      const dd = document.createElement('dd');
      if (classe) dd.className = classe;
      const sp = document.createElement('span'); sp.textContent = valor ?? '—';
      dd.append(sp);
      dl.append(dt, dd);
    }
  };
  const lista = (id, itens) => {
    const el = $('#' + id);
    if (!el) return;
    el.replaceChildren();
    if (!itens.length) { el.innerHTML = '<li class="lista-vazia">nada por aqui ainda</li>'; return; }
    for (const [estado, nome, sub, direita, selo] of itens) {
      const li = document.createElement('li');
      const esq = document.createElement('span'); esq.className = 'lista-nome';
      const p = document.createElement('i'); p.className = 'ponto ' + estado; p.setAttribute('aria-hidden', 'true');
      const t = document.createElement('span'); t.textContent = nome;
      if (sub) { const s = document.createElement('small'); s.textContent = sub; t.append(s); }
      esq.append(p, t);
      li.append(esq);
      if (direita) { const d = document.createElement('span'); d.className = 'selo ' + (selo || 'selo-neutro'); d.textContent = direita; li.append(d); }
      el.append(li);
    }
  };
  const stat = (id, valor, sub, pct, barraNivel) => {
    const el = $('#' + id);
    if (!el) return;
    $('.stat-card-value', el).innerHTML = valor;
    $('.stat-card-sub', el).textContent = sub || '';
    const b = $('.barra', el);
    if (b && pct != null) { $('i', b).style.width = Math.min(100, pct) + '%'; b.className = 'barra ' + (barraNivel ?? nivel(pct)); }
  };
  const disco = (id, usado, total) => {
    const el = $('#' + id);
    const pct = total ? usado / total * 100 : null;
    $('.num', el).textContent = total ? `${fmt.bytes(usado)} de ${fmt.bytes(total)}` : 'sem leitura';
    const b = $('.barra', el);
    $('i', b).style.width = (pct || 0) + '%';
    b.className = 'barra ' + (pct != null ? nivel(pct, 80, 92) : '');
  };

  function estadoAoVivo() {
    const el = $('#ao-vivo');
    if (!el) return;
    const txt = $('span', el);
    const idade = ultimo?.atual?.idade_s != null ? Number(ultimo.atual.idade_s) + (Date.now() - ultimoOk) / 1000 : null;
    if (pausado) { el.className = 'ao-vivo'; txt.textContent = 'Pausado'; return; }
    if (idade == null) { el.className = 'ao-vivo'; txt.textContent = 'aguardando coleta'; return; }
    if (idade > 120) { el.className = 'ao-vivo atrasado'; txt.textContent = 'Desatualizado · ' + fmt.dur(idade); return; }
    el.className = 'ao-vivo vivo';
    txt.textContent = `Ao vivo · há ${Math.round(idade)} s`;
  }

  function desenharGraficos() {
    if (!ultimo) return;
    const s = ultimo.serie || [], r = ultimo.serie_rede || [];
    const serie = (arr, k) => arr.map((p) => [Number(p.t), p[k] == null ? null : Number(p[k])]);
    const dias = periodo > 48;
    const box = (n) => $(`[data-grafico="${n}"]`);
    const pct = { min: 0, max: 100, formato: (v) => fmt.num(v, 1) + '%', formatoEixo: (v) => fmt.num(v, 0) + '%', dias };
    grafico(box('cpu'), [{ nome: 'CPU', cor: cor('--g1'), pontos: serie(s, 'cpu') }], { ...pct, titulo: 'Uso do processador' });
    grafico(box('ram'), [{ nome: 'RAM', cor: cor('--g2'), pontos: serie(s, 'ram') }], { ...pct, titulo: 'Uso de memória' });
    grafico(box('energia'), [{ nome: 'Energia', cor: cor('--g3'), pontos: serie(s, 'energia') }], { formato: (v) => fmt.num(v, 1) + ' W', formatoEixo: (v) => fmt.num(v, 0) + ' W', dias, titulo: 'Energia estimada na tomada' });
    grafico(box('temp'), [{ nome: 'Temperatura', cor: cor('--g1'), pontos: serie(s, 'temp') }], { formato: (v) => fmt.num(v, 0) + ' °C', formatoEixo: (v) => fmt.num(v, 0) + '°', dias, titulo: 'Temperatura do processador' });
    grafico(box('rede'), [
      { nome: 'Baixando', cor: cor('--g1'), pontos: serie(s, 'rx') },
      { nome: 'Enviando', cor: cor('--g2'), pontos: serie(s, 'tx') },
    ], { min: 0, formato: (v) => fmt.taxa(v), formatoEixo: (v) => fmt.bytes(v, 0), dias, titulo: 'Tráfego de rede' });
    const ms = { min: 0, formato: (v) => fmt.num(v, 1) + ' ms', formatoEixo: (v) => fmt.num(v, 0) + ' ms', dias };
    grafico(box('latencia'), [
      { nome: 'Internet', cor: cor('--g1'), pontos: serie(r, 'internet') },
      { nome: 'Roteador', cor: cor('--g2'), pontos: serie(r, 'gateway') },
    ], { ...ms, titulo: 'Latência da internet e do roteador' });
    grafico(box('internet-mini'), [{ nome: 'Internet', cor: cor('--g1'), pontos: serie(r, 'internet') }], { ...ms, mini: true, titulo: 'Latência da internet' });
  }
  redesenhar = desenharGraficos;

  function render(d) {
    const a = d.atual || {};
    const h = d.host || {};
    const alertas = d.alertas || [];
    const pctRam = a.ram_total ? a.ram_usada / a.ram_total * 100 : null;

    // ---- situação geral
    const erros = alertas.filter((x) => x.nivel === 'erro').length;
    const avisos = alertas.filter((x) => x.nivel === 'aviso').length;
    const sit = $('#s-situacao-ico');
    const valSit = $('#s-situacao .stat-card-value');
    if (erros) { sit.className = 'stat-card-icon danger'; sit.innerHTML = ico('alerta'); valSit.textContent = `${erros} problema${erros > 1 ? 's' : ''} no servidor`; }
    else if (avisos) { sit.className = 'stat-card-icon warning'; sit.innerHTML = ico('alerta'); valSit.textContent = `${avisos} ponto${avisos > 1 ? 's' : ''} de atenção`; }
    else { sit.className = 'stat-card-icon success'; sit.innerHTML = ico('ok'); valSit.textContent = 'Tudo funcionando'; }
    $('#uptime').textContent = fmt.dur(d.info.uptime_s);

    stat('s-cpu', a.cpu_pct != null ? fmt.num(a.cpu_pct, 1) + '<small>%</small>' : '—',
      a.load1 != null ? `carga ${fmt.num(a.load1, 2)} · ${d.info.nucleos} threads` : '', a.cpu_pct);
    stat('s-ram', pctRam != null ? fmt.num(pctRam, 0) + '<small>%</small>' : '—',
      a.ram_total ? `${fmt.bytes(a.ram_usada)} de ${fmt.bytes(a.ram_total)}` : '', pctRam);
    stat('s-temp', a.temp_c != null ? fmt.num(a.temp_c, 0) + '<small>°C</small>' : '—',
      a.temp_c == null ? 'sem sensor' : a.temp_c >= 85 ? 'muito quente' : a.temp_c >= 70 ? 'quente' : 'normal',
      a.temp_c, nivel(a.temp_c ?? 0, 70, 85));
    const en = d.energia || {};
    stat('s-energia', a.energia_total_w != null ? '≈ ' + fmt.num(a.energia_total_w, 1) + '<small>W</small>' : '—',
      en.kwh_mes != null ? `~${fmt.num(en.kwh_mes, 1)} kWh por mês` : '');
    stat('s-custo', en.reais_mes != null ? fmt.real(en.reais_mes) + '<small>/mês</small>' : '—',
      en.tarifa == null ? 'informe a tarifa' : `hoje ${fmt.real(en.reais_hoje ?? 0)} · ${fmt.real(en.tarifa)}/kWh`);

    // ---- pontos de atenção
    const n = $('#n-alertas');
    n.textContent = alertas.length;
    n.className = 'badge-count' + (erros ? ' tem-erro' : avisos ? ' tem-aviso' : '');
    const ul = $('#l-alertas');
    ul.replaceChildren();
    if (!alertas.length) {
      ul.innerHTML = `<li class="tudo-certo">${ico('ok')}Nenhum ponto de atenção</li>`;
    } else {
      for (const al of alertas) {
        const li = document.createElement('li'); li.className = al.nivel;
        li.innerHTML = `<span class="al-ico">${ico(al.icone)}</span>`;
        const txt = document.createElement('div');
        const st = document.createElement('strong'); st.textContent = al.titulo;
        const sm = document.createElement('small'); sm.textContent = al.detalhe || '';
        txt.append(st, sm); li.append(txt); ul.append(li);
      }
    }

    // ---- atividade
    const ol = $('#l-atividade');
    ol.replaceChildren();
    if (!(d.atividade || []).length) ol.innerHTML = '<li class="lista-vazia">nada registrado ainda</li>';
    for (const ev of d.atividade || []) {
      const li = document.createElement('li'); li.className = 'lt-item nivel-' + ev.nivel;
      li.innerHTML = `<span class="lt-ico">${ico(ICONE_TIPO[ev.tipo] || 'atividade')}</span>`;
      const txt = document.createElement('div'); txt.className = 'lt-txt';
      const t = document.createElement('p'); t.className = 'lt-titulo'; t.textContent = ev.titulo;
      txt.append(t);
      const det = [ev.detalhe, ev.tipo === 'acesso' && ev.origem ? 'IP ' + ev.origem : null].filter(Boolean).join(' · ');
      if (det) { const p = document.createElement('p'); p.className = 'lt-detalhe'; p.textContent = det; txt.append(p); }
      const tm = document.createElement('time'); tm.className = 'lt-hora'; tm.textContent = fmt.rel(Number(ev.ts)); tm.title = fmt.data(Number(ev.ts));
      li.append(txt, tm); ol.append(li);
    }

    // ---- serviços (containers)
    const conts = Array.isArray(h.containers) ? h.containers : null;
    if (conts) {
      const rodando = conts.filter((c) => c.estado === 'running').length;
      $('#n-servicos').textContent = `${rodando} de ${conts.length} rodando`;
      lista('l-servicos', conts.map((c) => {
        const ok = c.estado === 'running' && c.saude !== 'unhealthy';
        const desde = c.iniciado ? (Date.now() / 1000 - Date.parse(c.iniciado) / 1000) : null;
        const sub = c.estado === 'running' ? `no ar há ${fmt.dur(desde)}` + (c.reinicios ? ` · ${c.reinicios} reinício(s)` : '') : `código ${c.codigo_saida ?? '?'}`;
        return [ok ? 'ok' : 'erro', NOMES[c.nome] || c.nome, sub, ok ? 'Rodando' : (c.saude === 'unhealthy' ? 'Com problema' : 'Parado'), ok ? 'selo-ok' : 'selo-erro'];
      }));
    } else {
      $('#n-servicos').textContent = '';
      lista('l-servicos', (d.servicos || []).map((s) => [s.ok ? 'ok' : 'erro', s.nome, s.detalhe, s.ok ? 'OK' : 'Falha', s.ok ? 'selo-ok' : 'selo-erro']));
    }

    // ---- internet
    const r = h.rede || {};
    const net = r.internet || {}, gw = r.gateway || {};
    $('#net-ms').textContent = net.ms != null ? fmt.num(net.ms, net.ms < 10 ? 1 : 0) : '—';
    const selo = $('#net-selo');
    if (!h.disponivel) { selo.className = 'selo selo-neutro'; selo.textContent = 'sem dados'; }
    else if ((net.perda ?? 100) >= 100) { selo.className = 'selo selo-erro'; selo.textContent = 'Fora do ar'; }
    else if ((net.ms ?? 0) > 150 || (net.perda ?? 0) > 0) { selo.className = 'selo selo-aviso'; selo.textContent = 'Instável'; }
    else { selo.className = 'selo selo-ok'; selo.textContent = 'Online'; }
    const disp = d.disponibilidade || {};
    kv('kv-internet', [
      ['Perda de pacotes', net.perda != null ? fmt.num(net.perda, 0) + '%' : '—', net.perda > 0 ? 'aviso' : ''],
      ['Roteador', gw.ms != null ? `${fmt.num(gw.ms, 1)} ms · ${gw.host}` : '—'],
      ['DNS', r.dns_ms != null ? fmt.num(r.dns_ms, 0) + ' ms' : '—'],
      ['No ar (24 h / 7 dias)', disp.h24 != null ? `${fmt.num(disp.h24, 1)}% / ${fmt.num(disp.d7, 1)}%` : '—', disp.h24 != null && disp.h24 < 99 ? 'aviso' : ''],
      ['Endereço', r.ip ? `${r.ip} · ${r.interface}${r.velocidade_mbps ? ' · ' + r.velocidade_mbps + ' Mb/s' : ''}` : '—'],
    ]);

    // ---- backups
    const b = h.backups || {};
    kv('kv-backups', [
      ['Último', b.ultimo ? `${fmt.rel(b.ultimo.quando)} · ${fmt.bytes(b.ultimo.bytes)}` : 'nenhum ainda', b.ultimo ? (Date.now() / 1000 - b.ultimo.quando > 26 * 3600 ? 'erro' : 'ok') : 'aviso'],
      ['Próximo', b.proximo ? `${fmt.rel(b.proximo)} · ${fmt.hora(b.proximo)}` : '—'],
      ['Resultado', b.resultado ? (b.resultado === 'success' ? 'Sucesso' : 'Falhou') : '—', b.resultado && b.resultado !== 'success' ? 'erro' : ''],
      ['Cópias guardadas', b.quantidade != null ? `${b.quantidade} · ${fmt.bytes(b.total_bytes)}` : '—'],
    ]);

    // ---- manutenção
    const up = h.atualizacoes || {}, sis = h.sistema || {};
    kv('kv-manutencao', [
      ['Atualizações', up.pendentes == null ? '—' : up.pendentes === 0 ? 'Em dia' : `${up.pendentes} pendente(s)` + (up.seguranca ? ` · ${up.seguranca} de segurança` : ''), up.pendentes ? (up.seguranca ? 'aviso' : '') : 'ok'],
      ['Verificado', up.verificado_em ? fmt.rel(up.verificado_em) : '—'],
      ['Reinício pendente', sis.reiniciar == null ? '—' : sis.reiniciar ? 'Sim' : 'Não', sis.reiniciar ? 'aviso' : 'ok'],
      ['Serviços do sistema', sis.unidades_falhando ? (sis.unidades_falhando.length ? sis.unidades_falhando.join(', ') : 'Nenhuma falha') : '—', sis.unidades_falhando?.length ? 'erro' : 'ok'],
      ['Relógio', sis.hora_sincronizada == null ? '—' : sis.hora_sincronizada ? `Sincronizado${sis.desvio_hora_ms != null ? ' · ' + fmt.num(Math.abs(sis.desvio_hora_ms), 2) + ' ms' : ''}` : 'Fora de sincronia', sis.hora_sincronizada ? 'ok' : 'aviso'],
      ['Kernel', sis.kernel ? sis.kernel.replace(/\.x86_64$/, '') : '—'],
    ]);

    // ---- segurança
    const sg = h.seguranca || {}, ssh = h.ssh || {};
    kv('kv-seguranca', [
      ['SELinux', sg.selinux || '—', sg.selinux === 'Enforcing' ? 'ok' : 'aviso'],
      ['Firewall', sg.firewall == null ? '—' : sg.firewall ? 'Ativo' : 'Desligado', sg.firewall ? 'ok' : 'erro'],
      ['Criptografia FIPS', sg.fips == null ? '—' : sg.fips ? 'Ativa' : 'Desligada'],
      ['Root no SSH', sg.ssh_root ? ({ yes: 'Com senha', 'prohibit-password': 'Só com chave', no: 'Bloqueado', 'without-password': 'Só com chave' }[sg.ssh_root] || sg.ssh_root) : '—', sg.ssh_root === 'yes' && sg.ssh_senha ? 'aviso' : 'ok'],
      ['SSH: senhas erradas (24 h)', ssh.falhas_24h != null ? String(ssh.falhas_24h) : '—', ssh.falhas_24h > 20 ? 'aviso' : ''],
      ['SSH: entradas (24 h)', ssh.entradas_24h != null ? String(ssh.entradas_24h) : '—'],
    ]);
    lista('l-ssh', (ssh.ultimas || []).slice(0, 3).map((e) => ['info', `${e.usuario} de ${e.ip}`, e.metodo === 'publickey' ? 'por chave' : 'por senha', fmt.rel(e.quando), 'selo-neutro']));

    // ---- armazenamento e energia
    disco('d-raiz', a.disco_usado, a.disco_total);
    disco('d-home', a.home_usado, a.home_total);
    kv('kv-energia', [
      ['Agora (na tomada)', a.energia_total_w != null ? `≈ ${fmt.num(a.energia_total_w, 1)} W` : '—', 'ambar'],
      ['Medido CPU + RAM', a.energia_w != null ? `${fmt.num(a.energia_w, 1)} W` : '—'],
      ['Consumo por mês', en.kwh_mes != null ? `${fmt.num(en.kwh_mes, 1)} kWh` : '—'],
      ['Custo por mês', en.reais_mes != null ? fmt.real(en.reais_mes) : (en.tarifa == null ? 'informe a tarifa' : '—'), 'ambar'],
      ['Hoje', en.reais_hoje != null ? fmt.real(en.reais_hoje) : '—'],
    ]);

    // ---- aparelhos
    lista('l-aparelhos', (d.aparelhos || []).filter((x) => x.ativo).map((x) => {
      if (x.erro) return ['erro', x.nome, x.erro, 'Com erro', 'selo-erro'];
      if (x.pareada === false) return ['aviso', x.nome, 'aceite o pareamento na TV', 'Não pareada', 'selo-aviso'];
      if (x.online && x.ligado) return ['ok', x.nome, x.app || '', 'Ligada', 'selo-ok'];
      if (x.online) return ['aviso', x.nome, '', 'Em espera', 'selo-aviso'];
      return ['', x.nome, x.comodo || '', 'Desligada', 'selo-neutro'];
    }));

    // ---- leituras dos gráficos
    $('#l-cpu').textContent = a.cpu_pct != null ? `agora ${fmt.num(a.cpu_pct, 1)}%` : '';
    $('#l-ram').textContent = pctRam != null ? `agora ${fmt.num(pctRam, 0)}%` : '';
    $('#l-energia').textContent = a.energia_total_w != null ? `agora ≈ ${fmt.num(a.energia_total_w, 1)} W` : '';
    $('#l-temp').textContent = a.temp_c != null ? `agora ${fmt.num(a.temp_c, 0)} °C` : '';
    $('#l-rx').textContent = fmt.taxa(a.rede_rx_bps);
    $('#l-tx').textContent = fmt.taxa(a.rede_tx_bps);

    desenharGraficos();
    estadoAoVivo();
  }

  atualizarDashboard = async function () {
    try {
      const d = await api('GET', '/painel/dados?h=' + periodo);
      ultimo = d; ultimoOk = Date.now();
      render(d);
    } catch (e) {
      const el = $('#ao-vivo');
      if (el) { el.className = 'ao-vivo atrasado'; $('span', el).textContent = 'Sem conexão'; }
    }
  };

  function agendar() {
    clearInterval(timer);
    if (!pausado) timer = setInterval(() => { if (!document.hidden) atualizarDashboard(); }, 15000);
  }
  $('#pausar')?.addEventListener('click', (e) => {
    pausado = !pausado;
    const b = e.currentTarget;
    b.setAttribute('aria-pressed', String(pausado));
    b.setAttribute('aria-label', pausado ? 'Retomar atualização automática' : 'Pausar atualização automática');
    b.title = pausado ? 'Retomar atualização' : 'Pausar atualização';
    agendar();
    if (!pausado) atualizarDashboard();
    estadoAoVivo();
  });
  $$('[data-periodo]').forEach((b) => b.addEventListener('click', () => {
    periodo = Number(b.dataset.periodo);
    $$('[data-periodo]').forEach((x) => x.setAttribute('aria-checked', String(x === b)));
    atualizarDashboard();
  }));
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !pausado) atualizarDashboard(); });
  setInterval(estadoAoVivo, 5000);
  let rz;
  addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(desenharGraficos, 200); });

  atualizarDashboard();
  agendar();
}
