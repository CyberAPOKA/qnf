# Patches temporários

## `whatsapp-web.js+1.34.6.patch`

Workaround **temporário** para um bug upstream do `whatsapp-web.js` no envio de mídia.

- **Sintoma:** `Data passed to getter must include an id property (it's how we memoize) but got undefined`
- **Causa:** `processMediaData()` devolve um `MediaData` com propriedades internas enumeráveis (incluindo `__x_id`). Ao montar a mensagem em `src/util/Injected/Utils.js`, o spread de `mediaOptions` pode colidir com o ID interno da nova mensagem.
- **Correção:** `delete message.__x_id` imediatamente após a construção do objeto da mensagem. Nenhuma outra propriedade de mídia é removida.
- **Escopo:** não altera autenticação, `LocalAuth`, Puppeteer, Chromium nem o fluxo de mensagens de texto.
- **Remoção:** apague este patch quando o upstream corrigir o vazamento de `__x_id` no envio de mídia.

A versão instalada e pinada é `whatsapp-web.js@1.34.6` (o mesmo trecho existe no bug reportado em 1.34.7). O pin evita que um `npm install` puxe outra versão e ignore este patch.

Aplicado automaticamente por `patch-package` no `postinstall` (`npm install`).
