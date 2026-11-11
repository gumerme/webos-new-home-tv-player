// Estado compartilhado do app e registro de ações (evita importações circulares entre telas).

export const S = {
  tvNome: '',          // nome desta TV no servidor (estado.tv.nome)
  tvStatus: '',
  perfil: null,        // id do perfil atual
  perfis: [],
  prefs: {},           // preferências do perfil (servidor)
  ajustes: {},         // ajustes locais desta TV
  conta: null,         // cópia de GET conta (para tocar sem servidor)
  estado: null,
  online: false,
  inicio: null,        // última resposta de GET inicio
  tab: 'inicio',
  sub: {},             // submenu escolhido por aba
  avisosLigados: {},   // chave -> id do aviso (tmdb:123, ep:..., prog:..., jogo:...)
  playing: null
};

/** Funções registradas pelo main (abrir detalhe, tocar, avisar...). */
export const A = {};

export function perfilAtual() {
  for (let i = 0; i < S.perfis.length; i++) if (S.perfis[i].id === S.perfil) return S.perfis[i];
  return null;
}
export function prefOn(chave, padrao) {
  const v = S.prefs[chave];
  if (v == null || v === '') return padrao;
  return v === true || v === 1 || v === '1' || v === 'sim' || v === 'true';
}
