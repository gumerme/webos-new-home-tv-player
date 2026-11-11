'use strict';

/*
 * homebridge-casa — mostra no Apple Home (e na Siri) os aparelhos do sistema da casa.
 * O PHP é a fonte de verdade: a lista vem de GET /api/dispositivos?homekit=1 e
 * cada ação vira POST /api/dispositivos/{slug}/comandos. Aparelho novo no PHP
 * aparece aqui sozinho, sem mexer neste plugin.
 *
 * TVs são publicadas como acessórios externos (exigência da Apple para "Televisão"):
 * cada uma é adicionada à parte no app Casa, com o mesmo código do bridge.
 */

const PLUGIN = 'homebridge-casa';
const PLATAFORMA = 'Casa';

module.exports = (api) => {
  api.registerPlatform(PLATAFORMA, CasaPlatform);
};

class CasaPlatform {
  constructor(log, config, api) {
    this.log = log;
    this.api = api;
    this.config = config || {};
    this.url = String(this.config.url || 'http://127.0.0.1:8080').replace(/\/+$/, '');
    this.token = this.config.token || '';
    this.intervalo = Math.max(2, Number(this.config.intervalo) || 5) * 1000;
    this.S = api.hap.Service;
    this.C = api.hap.Characteristic;
    this.cache = new Map();   // UUID -> acessório restaurado do disco
    this.ativos = new Map();  // slug -> { acc, d, svc, sp?, externo }
    this.limpouCache = false;

    if (!this.token) {
      this.log.error('Falta "token" na configuração da plataforma Casa.');
      return;
    }
    api.on('didFinishLaunching', () => this.iniciar());
  }

  configureAccessory(acc) {
    this.cache.set(acc.UUID, acc);
  }

  async iniciar() {
    await this.sincronizar();
    setInterval(() => this.sincronizar().catch((e) => this.log.warn(e.message)), this.intervalo);
  }

  // ------------------------------------------------------------------ API

  async req(metodo, caminho, corpo) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 15000);
    try {
      const r = await fetch(this.url + caminho, {
        method: metodo,
        signal: ctrl.signal,
        headers: { Authorization: 'Bearer ' + this.token, 'Content-Type': 'application/json' },
        body: corpo ? JSON.stringify(corpo) : undefined,
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) {
        throw new Error(j.erro || (j.resposta && j.resposta.erro) || `HTTP ${r.status}`);
      }
      return j;
    } finally {
      clearTimeout(t);
    }
  }

  async comando(item, acao, parametros = {}) {
    try {
      const r = await this.req('POST', `/api/dispositivos/${item.d.slug}/comandos`, { acao, parametros, origem: 'homekit' });
      item.d.dados = Object.assign(item.d.dados || {}, (r.resposta && r.resposta.estado) || {});
      this.log.info(`${item.d.nome}: ${acao} ok`);
    } catch (e) {
      this.log.error(`${item.d.nome}: ${acao} falhou: ${e.message}`);
      throw new this.api.hap.HapStatusError(this.api.hap.HAPStatus.SERVICE_COMMUNICATION_FAILURE);
    }
  }

  // --------------------------------------------------------- sincronização

  async sincronizar() {
    let lista;
    try {
      lista = (await this.req('GET', '/api/dispositivos?homekit=1')).dispositivos || [];
    } catch (e) {
      this.log.warn('Sistema da casa não respondeu: ' + e.message);
      return;
    }
    const vistos = new Set();
    for (const d of lista) {
      vistos.add(d.slug);
      let item = this.ativos.get(d.slug);
      if (!item) {
        item = d.tipo === 'tv' ? this.criarTv(d) : this.criarSimples(d);
        if (!item) continue;
        this.ativos.set(d.slug, item);
      }
      item.d = d;
      this.atualizar(item);
    }
    for (const [slug, item] of this.ativos) {
      if (!vistos.has(slug) && !item.externo) {
        this.api.unregisterPlatformAccessories(PLUGIN, PLATAFORMA, [item.acc]);
        this.ativos.delete(slug);
        this.log.info('Removido do Apple Home: ' + slug);
      }
    }
    if (!this.limpouCache) {
      this.limpouCache = true;
      const usados = new Set([...this.ativos.values()].map((i) => i.acc.UUID));
      const sobra = [...this.cache.values()].filter((a) => !usados.has(a.UUID));
      if (sobra.length) this.api.unregisterPlatformAccessories(PLUGIN, PLATAFORMA, sobra);
    }
  }

  atualizar(item) {
    const on = this.ligado(item);
    if (item.externo) {
      item.svc.updateCharacteristic(this.C.Active, on ? 1 : 0);
      const dados = item.d.dados || {};
      if (item.sp) {
        if (dados.volume != null) item.sp.updateCharacteristic(this.C.Volume, dados.volume);
        item.sp.updateCharacteristic(this.C.Mute, !!dados.mudo);
      }
      if (item.apps) item.svc.updateCharacteristic(this.C.ActiveIdentifier, this.indiceApp(item));
    } else {
      item.svc.updateCharacteristic(this.C.On, on);
    }
  }

  ligado(item) {
    return !!(item.d.online && item.d.dados && item.d.dados.ligado);
  }

  info(acc, d) {
    const cfg = d.config || {};
    acc.getService(this.S.AccessoryInformation)
      .setCharacteristic(this.C.Manufacturer, cfg.fabricante || (d.driver === 'lgwebos' ? 'LG' : 'Casa'))
      .setCharacteristic(this.C.Model, cfg.modelo || d.tipo)
      .setCharacteristic(this.C.SerialNumber, d.slug);
  }

  // -------------------------------------------- tomada / interruptor / lâmpada

  criarSimples(d) {
    const tipos = { tomada: this.S.Outlet, interruptor: this.S.Switch, lampada: this.S.Lightbulb };
    const Tipo = tipos[d.tipo];
    if (!Tipo) {
      this.log.warn(`Tipo "${d.tipo}" (${d.slug}) ainda não tem modelo no Apple Home.`);
      return null;
    }
    const uuid = this.api.hap.uuid.generate('casa:' + d.slug);
    let acc = this.cache.get(uuid);
    const novo = !acc;
    if (novo) acc = new this.api.platformAccessory(d.nome, uuid);
    acc.context.slug = d.slug;
    this.info(acc, d);
    const svc = acc.getService(Tipo) || acc.addService(Tipo, d.nome);
    const item = { acc, d, svc, externo: false };
    svc.getCharacteristic(this.C.On)
      .onGet(() => {
        if (!item.d.online) throw new this.api.hap.HapStatusError(this.api.hap.HAPStatus.SERVICE_COMMUNICATION_FAILURE);
        return this.ligado(item);
      })
      .onSet((v) => this.comando(item, v ? 'ligar' : 'desligar'));
    if (novo) this.api.registerPlatformAccessories(PLUGIN, PLATAFORMA, [acc]);
    return item;
  }

  // ------------------------------------------------------------------ TV

  criarTv(d) {
    const uuid = this.api.hap.uuid.generate('casa:' + d.slug);
    const acc = new this.api.platformAccessory(d.nome, uuid, this.api.hap.Categories.TELEVISION);
    acc.context.slug = d.slug;
    this.info(acc, d);

    const tv = acc.addService(this.S.Television, d.nome);
    tv.setCharacteristic(this.C.ConfiguredName, d.nome);
    tv.setCharacteristic(this.C.SleepDiscoveryMode, this.C.SleepDiscoveryMode.ALWAYS_DISCOVERABLE);
    const apps = Array.isArray(d.config && d.config.apps) ? d.config.apps.slice(0, 20) : [];
    const item = { acc, d, svc: tv, externo: true, apps };

    tv.getCharacteristic(this.C.Active)
      .onGet(() => (this.ligado(item) ? 1 : 0))
      .onSet((v) => this.comando(item, v ? 'ligar' : 'desligar'));

    // Apps favoritos aparecem como "entradas" da TV (config.apps = [{id, nome}]).
    apps.forEach((a, i) => {
      const inp = acc.addService(this.S.InputSource, a.nome, 'app-' + a.id);
      inp.setCharacteristic(this.C.Identifier, i + 1)
        .setCharacteristic(this.C.ConfiguredName, a.nome)
        .setCharacteristic(this.C.IsConfigured, this.C.IsConfigured.CONFIGURED)
        .setCharacteristic(this.C.InputSourceType, this.C.InputSourceType.APPLICATION)
        .setCharacteristic(this.C.CurrentVisibilityState, this.C.CurrentVisibilityState.SHOWN);
      tv.addLinkedService(inp);
    });
    tv.getCharacteristic(this.C.ActiveIdentifier)
      .onGet(() => this.indiceApp(item))
      .onSet((v) => {
        const a = apps[v - 1];
        if (a) return this.comando(item, 'abrir_app', { app: a.id });
      });

    // Controle remoto da Central de Controle: setas/OK ainda não implementadas no driver.
    tv.getCharacteristic(this.C.RemoteKey).onSet((k) => this.log.debug(`${d.nome}: tecla ${k} (ainda não suportada)`));

    const sp = acc.addService(this.S.TelevisionSpeaker);
    sp.setCharacteristic(this.C.VolumeControlType, this.C.VolumeControlType.ABSOLUTE);
    sp.getCharacteristic(this.C.VolumeSelector)
      .onSet((v) => this.comando(item, v === this.C.VolumeSelector.INCREMENT ? 'volume_mais' : 'volume_menos'));
    sp.getCharacteristic(this.C.Mute)
      .onGet(() => !!(item.d.dados && item.d.dados.mudo))
      .onSet((v) => this.comando(item, 'mudo', { valor: !!v }));
    sp.getCharacteristic(this.C.Volume)
      .onGet(() => (item.d.dados && item.d.dados.volume) || 0)
      .onSet((v) => this.comando(item, 'volume', { valor: v }));
    tv.addLinkedService(sp);
    item.sp = sp;

    this.api.publishExternalAccessories(PLUGIN, [acc]);
    this.log.info(`TV "${d.nome}" publicada. No app Casa: Adicionar Acessório > Mais opções, mesmo código do bridge.`);
    return item;
  }

  indiceApp(item) {
    const atual = item.d.dados && item.d.dados.app;
    const i = item.apps.findIndex((a) => a.id === atual);
    return i >= 0 ? i + 1 : 0;
  }
}
