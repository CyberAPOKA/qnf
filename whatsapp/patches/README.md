# Patches temporários

## `whatsapp-web.js+1.34.6.patch`

Workaround **temporário** para um bug upstream do `whatsapp-web.js` no envio de mídia.

- **Sintoma:** `Data passed to getter must include an id property (it's how we memoize) but got undefined`
- **Causa:** `processMediaData()` devolve um `MediaData` do Store com propriedades internas enumeráveis (`__x_id`, e às vezes `id`/`from` indefinidos). O spread de `mediaOptions` em `src/util/Injected/Utils.js` sobrescreve a chave e o remetente da mensagem nova. Só apagar `__x_id` não restaura `id`/`from`.
- **Correção:** depois de montar o objeto da mensagem, remover chaves `__x_*` e reatribuir `id`, `from`, `to` e `participant`.
- **Escopo:** não altera autenticação, `LocalAuth`, Puppeteer, Chromium nem o fluxo de mensagens de texto.
- **Remoção:** apague este patch quando o upstream deixar de espalhar o `MediaData` cru no objeto da mensagem.

A versão instalada e pinada é `whatsapp-web.js@1.34.6` (o mesmo trecho existe no bug reportado em 1.34.7). O pin evita que um `npm install` puxe outra versão e ignore este patch.

Aplicado automaticamente por `patch-package` no `postinstall` (`npm install`).
