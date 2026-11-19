---
title: Aprobar los cambios del asistente
description: Cada creación, actualización o eliminación que propone la IA espera su revisión. Nada se guarda hasta que usted lo apruebe.
order: 2
updated: "2026-11-06"
related: [help/ai-assistant/ask-questions-about-your-data, help/getting-started/use-custom-fields, help/email-and-calendar/use-your-email-with-an-ai-assistant]
---

Cuando le pide al asistente que cambie algo ("cree una tarea de seguimiento
para Acme", "mueva esta oportunidad a Negociación", "elimine estas tres
notas"), nunca escribe directamente en su CRM. Redacta el cambio como una
tarjeta de propuesta en el lugar del cuadro de mensaje y espera. Apruébela o
descártela para seguir conversando.

![Una tarjeta de propuesta para "Create task Send updated proposal to Figma", con su título, la empresa y la oportunidad vinculadas, el estado, la fecha de vencimiento y la prioridad, y los botones Discard y Approve](/help-assets/ai-assistant/approve-what-the-assistant-changes-1.png)

## La tarjeta de propuesta

La tarjeta muestra cada cambio en una línea, con todos los valores que
establece. En una actualización, el valor anterior aparece tachado junto al
nuevo. Desde ahí puede hacer lo siguiente:

- **Aprobar**. El botón cuenta lo que escribe: **Aprobar**, o
  **Aprobar 3 cambios** si son varios. Una eliminación dice **Eliminar 2 tareas**
  en rojo. `⌘↵` aprueba desde el teclado, salvo en eliminaciones, correos,
  invitaciones y sobrescrituras, que siempre piden un clic.
- **Editar un campo antes**. Haga clic en el lápiz junto a un valor, corríjalo y
  luego en **Guardar**. Apruebe cuando la tarjeta se vea bien.
- **Excluir algo**. Pase el cursor sobre un campo, una línea o un registro y haga
  clic en su **×**. Se queda en la tarjeta, tachado, con **Conservar** para
  recuperarlo. Nada se decide hasta que presione **Aprobar**.
- **Descartar**. No se escribe nada.

El asistente puede establecer cualquier campo que usted podría establecer en el
formulario del propio registro, incluidos los atributos personalizados de su
espacio de trabajo, y cada uno aparece en la tarjeta para su revisión.

## Cuando alguien cambió el registro mientras tanto

Si alguien edita un campo después de que el asistente propuso cambiarlo, la
tarjeta indica quién lo cambió, a qué valor y cuándo. Aprobar entonces requiere
un clic, nunca el atajo de teclado. En una sola actualización, el botón dice
**Sobrescribir**. Excluya ese campo para conservar el valor de la otra persona
(en un lote o un plan, excluya ese registro), o descarte la propuesta. Una
edición a un campo que la propuesta no toca no cambia nada.

Si la edición llega mientras la tarjeta está abierta, su primer clic muestra el
cambio en lugar de aprobar. Haga clic de nuevo cuando lo haya leído.

## Lotes y planes

Una solicitud puede proponer hasta 25 registros. "Agregue a estas cinco personas
de mis notas de la reunión" llega como una tarjeta que enumera las cinco. Excluya
las que no quiera y apruebe el resto. Las eliminaciones funcionan igual.

Una solicitud que necesita varios cambios vinculados, como una empresa nueva con
una persona y una tarea asociadas, llega como un plan de hasta 5 pasos
vinculados. Un registro que crea el plan y al que otro cambio se vincula se marca
como **nuevo**. Si excluye ese registro, los cambios que se vinculan a él se
van con él. La tarjeta le indica cuáles, con **Conservar** para recuperarlos.
Usted aprueba el plan una sola vez.

## Después de decidir

La tarjeta desaparece y la conversación guarda un comprobante: **Se aplicaron 3
cambios** con una línea por registro, cada una con un enlace a él. Lo que haya
excluido aparece tachado. Una propuesta descartada deja una línea gris. La más
reciente ofrece **Proponer de nuevo**.

## Correo electrónico

Un correo se aprueba de uno en uno. Su tarjeta muestra todos los destinatarios y
el mensaje completo, y su botón dice **Enviar correo electrónico**. Un plan
muestra el correo como "Luego enviar correo electrónico …" y nunca lo envía junto con el
resto: cuando aprueba el plan, el correo recibe una tarjeta para él solo y
espera su propio **Enviar correo electrónico**.
Consulte [Use su correo con un asistente de IA](/es/help/email-and-calendar/use-your-email-with-an-ai-assistant).

## Invitaciones

Invitar a alguien a su espacio de trabajo le envía un correo, así que una
invitación también tiene su propio botón: **Enviar invitación**. Aprobar un plan
nunca la envía.

## Las propuestas no esperan para siempre

- Mientras una propuesta espera, la tarjeta ocupa el lugar del cuadro de
  mensaje. Apruébela o descártela para seguir conversando.
- Una propuesta sin tocar vence a las 24 horas y su línea dice
  **vencido**. Si es la más reciente, **Proponer de nuevo** le da una tarjeta
  nueva.
- No puede regenerar una respuesta mientras su propuesta está pendiente.
  Resuelva primero la tarjeta.

## Quién puede proponer qué

El asistente actúa con sus permisos. Crear y editar las definiciones de
atributos personalizados desde el chat es territorio del propietario y los
administradores, igual que la propia página **Atributos personalizados**. Para
los demás, el asistente lo explica y los dirige allí con un enlace.
