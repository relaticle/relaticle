---
title: Use su correo con un asistente de IA
description: Deje que Rela, Claude o ChatGPT lean el correo que usted puede ver, guarden borradores y envíen por usted. Usted aprueba o cancela cada envío.
order: 9
updated: "2026-11-08"
related: [help/email-and-calendar/choose-who-sees-your-email, help/ai-assistant/connect-claude-or-chatgpt, help/ai-assistant/approve-what-the-assistant-changes, help/workspace/manage-members-and-roles]
---

Rela, el asistente de IA integrado, puede trabajar con su correo sincronizado.
También puede hacerlo un asistente conectado, como Claude o ChatGPT. Cada uno
recibe lo que permite su rol en el espacio de trabajo, y ninguno ve más de lo
que usted ve en Relaticle.

## Pregúntele a Rela por su correo

Rela no necesita más configuración que un buzón conectado. Pídale en el chat:
"¿qué dijo Lena sobre las tarifas del cuarto trimestre?", "redacte una respuesta que confirme el jueves" o "envíele a Lena la nueva lista de precios por correo".

- **Lectura.** Rela lee el correo con el nivel de uso compartido que tiene para
  usted. La tabla de
  [Qué puede leer el asistente](#qué-puede-leer-el-asistente)
  también se aplica a Rela.
- **Borradores.** Rela propone un borrador en una tarjeta. Haga clic en
  **Guardar borrador** y se guarda en sus **Borradores**, donde usted lo revisa
  antes de enviar.
- **Envío.** Rela propone el correo en una tarjeta que muestra el buzón, a cada
  destinatario, el asunto, el mensaje y su firma. Nada sale hasta que usted haga
  clic en **Enviar correo electrónico**.

Después de hacer clic en **Enviar correo electrónico**, un botón **Deshacer**
permanece en pantalla durante cinco segundos. Luego el correo sale desde su
propio buzón.

Cada correo tiene su propia tarjeta y su propio clic en **Enviar correo
electrónico**. Cuando Rela propone un correo junto con otros cambios, aprobar el
plan guarda los demás cambios, y cada correo queda en su propia tarjeta a la
espera de usted.

Los roles Propietario, Administrador y Miembro pueden enviar a través de Rela.
Un Lector puede leer y guardar borradores.

## Conecte Claude o ChatGPT

Un conector que usted agregó antes de que existiera el acceso al correo no tiene
ese acceso hasta que lo conecte de nuevo.

### Dé acceso al correo a un conector

1. Si el asistente ya está conectado, abra **Tokens de acceso** desde el menú de
   su avatar, búsquelo en **Conectores de IA** y haga clic en **Revocar**.
2. Agregue Relaticle al asistente de nuevo. Los pasos están en
   [Conecte Claude o ChatGPT a Relaticle](/es/help/ai-assistant/connect-claude-or-chatgpt).
3. En la pantalla de consentimiento de Relaticle, elija el espacio de trabajo.
4. Lea la lista de lo que el asistente podrá hacer y luego haga clic en
   **Autorizar**.

La lista sigue su rol en el espacio de trabajo que eligió.

| En la lista | Qué puede hacer el asistente | Quién lo recibe |
|---|---|---|
| **Leer el correo que usted puede ver en este espacio de trabajo** | Leer el correo que usted ya puede ver en este espacio de trabajo. | Todos los roles |
| **Guardar borradores de correo para que usted los revise** | Escribir un borrador en sus Borradores. No se envía nada. | Todos los roles |
| **Enviar correo en su nombre, tras una espera que puede cancelar** | Enviar desde un buzón que usted conectó, tras una espera de cinco minutos. | Propietario, Administrador, Miembro |

Un conector recibe todo lo que aparece en la lista. Para darle menos a un
asistente, use un token de acceso.

### Actívelo para un token de acceso

Abra **Tokens de acceso** desde el menú de su avatar. Marque **Leer correo**,
**Redactar borradores de correo** o **Enviar correo** al crear un token. Para un
token que ya tiene, haga clic en su ícono de **Permisos**, cambie las casillas y
guarde.

## Qué puede leer el asistente

El asistente lee un correo con el nivel de uso compartido que ese correo tiene
para usted. Los nombres de los niveles son los de
[Elija quién ve su correo](/es/help/email-and-calendar/choose-who-sees-your-email).

| Nivel de uso compartido | Qué obtiene el asistente |
|---|---|
| Su propio correo | Todo, incluidos los destinatarios en CCO. |
| **Acceso completo** | El remitente, los destinatarios en Para y CC, el asunto, el texto del mensaje y los nombres de los archivos adjuntos. |
| **Asunto y participantes** | El remitente, los destinatarios en Para, el asunto y la hora. |
| **Solo participantes** | El remitente, los destinatarios en Para y la hora. |
| **Privado** | Nada. El correo no aparece. |

Un mensaje muy largo se corta, y se le avisa al asistente que se cortó. Nunca
recibe el contenido de un archivo adjunto.

Solo aparece el correo que se entregó. Los borradores y el correo en espera de
envío no aparecen. Las conversaciones internas de otro miembro permanecen
ocultas. Si pide el correo de una persona o empresa protegida o bloqueada, el
asistente no recibe nada.

Cuando un correo muestra menos de lo que el asistente necesita, puede
[pedir más acceso al miembro](/es/help/email-and-calendar/request-access-to-an-email).

## Borradores y envío

El asistente escribe texto sin formato o markdown, y Relaticle lo convierte en
el correo. El correo no lleva imágenes ni archivos adjuntos. La firma
predeterminada de su buzón se agrega, a menos que el asistente la omita. Un
borrador va a sus **Borradores**, y usted lo revisa antes de enviar.

Un correo que envía un asistente conectado espera cinco minutos antes de salir.
Se envía desde un buzón que usted mismo conectó, nunca desde el de otro miembro.
Rela no tiene esa espera de cinco minutos, porque usted aprueba cada correo en su
tarjeta antes.

1. Recibe una notificación, como "Claude puso un correo electrónico en cola".
   Indica el asunto y los destinatarios.
2. Haga clic en **Cancelar envío** en esa notificación para detenerlo.
3. También puede abrir la página **Correos**, luego **Bandeja de salida**, y
   cancelar el correo allí.

Pasados los cinco minutos, el correo sale y no se puede cancelar. Luego aparece
en su correo sincronizado como cualquier otro.

Los roles Propietario, Administrador y Miembro pueden enviar a través de un
asistente. Un Lector no. Si su rol cambia a Lector antes de que salga un correo
en cola, no se envía. Consulte
[Administre miembros y roles](/es/help/workspace/manage-members-and-roles).

## Desactívelo

Para un conector, haga clic en **Revocar** en **Conectores de IA**. Los correos
que puso en cola y que aún no salieron no se envían. Esperan en la **Bandeja de
salida**, en **Fallidos**, donde usted puede enviar uno con **Reintentar**. Un
conector no tiene un ajuste solo para el correo, así que un asistente que deba
conservar sus registros sin su correo necesita un token de acceso con las
casillas de correo vacías. Para un token de acceso, haga clic en su ícono de
**Permisos** y desmarque las casillas de correo.

## Antes de conceder el envío

Un asistente actúa según lo que lee, y el texto de un correo proviene de
remitentes externos. Conceda el envío solo a un asistente de confianza, y
cancele todo lo que usted no haya pedido.
