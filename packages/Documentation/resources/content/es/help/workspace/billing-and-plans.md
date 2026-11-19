---
title: Facturación y planes
description: Relaticle Cloud se cobra por espacio de trabajo, nunca por usuario. Pruebas, suscripciones, créditos y pausas, explicados.
order: 3
updated: "2026-11-08"
related: [help/ai-assistant/ai-credits-and-limits, help/workspace/rename-or-delete-your-workspace]
---

Relaticle Cloud se factura **por espacio de trabajo, nunca por usuario**, así que
puede invitar a todo su equipo sin que el precio cambie. La página
**Facturación** está bajo el nombre de su espacio de trabajo en la barra lateral.
Las acciones de suscripción allí corresponden al propietario del espacio de
trabajo, y los demás miembros ven a quién pedírselas.

## Cuánto cuesta Cloud Pro

Cloud Pro es el plan de entrada: usuarios y registros ilimitados, 2,000 créditos
de IA al mes, modelos de IA premium, hasta 10 buzones conectados, la API REST y
el MCP server, y soporte por correo. Cuesta **$19/mes por espacio de trabajo con
facturación anual** ($228 al año), o **$24/mes con facturación mensual**. Para despliegues más grandes, la implementación Enterprise comienza en $20,000 al año. La [página de precios](/es/pricing) tiene los detalles.

## Cuánto cuesta Cloud Scale

Cloud Scale es para los equipos que trabajan todo el día en Relaticle. Incluye
todo lo de Cloud Pro, sube la asignación a 5,000 créditos de IA al mes, permite
hasta 50 buzones conectados, triplica el rendimiento de la API REST y del MCP
server, y viene con soporte prioritario. Cuesta **$49/mes por espacio de trabajo
con facturación anual** ($588 al año), o **$59/mes con facturación mensual**.
Cambie entre Pro y Scale desde la página de Facturación. Stripe prorratea el
cambio.

## La prueba

Su primer espacio de trabajo de Cloud inicia **automáticamente una prueba de Pro
de 14 días, sin tarjeta**. Un espacio de trabajo que agregue después inicia su
propia prueba solo mientras usted tenga una suscripción en otro. De lo contrario,
se abre en la elección de plan. La prueba incluye todo lo de Cloud Pro, con 300
créditos de IA y un buzón conectado. Los modelos de IA premium se desbloquean
cuando el espacio de trabajo tiene un registro propio. Si se suscribe durante la
prueba, la asignación completa del plan comienza de inmediato.
Cuando termina, suscríbase desde la página de Facturación para conservar el
acceso a Cloud. Sin suscripción, el espacio de trabajo se pausa. Si necesita más
tiempo, el propietario puede extender la prueba 7 días, una sola vez, desde la
página de Facturación. Los créditos de IA no se renuevan durante la extensión.
Relaticle Cloud no tiene plan gratuito.

Conservamos un espacio de trabajo de prueba en pausa durante 60 días después del
último inicio de sesión. Si nadie inicia sesión ni elige un plan en ese tiempo,
lo eliminamos junto con sus registros. Antes enviamos dos correos al propietario.

## Administre la suscripción

**Administrar suscripción** abre el portal de facturación, donde usted actualiza
el método de pago, descarga facturas y cambia entre pago mensual y anual. Si
cancela, conserva su plan hasta el final del período pagado. En Enterprise, el
portal es donde usted paga las facturas y actualiza el método de pago. Los
cambios de plan pasan por nosotros.

## Si la suscripción vence

El espacio de trabajo se **pausa**. La aplicación, la API, el MCP server y Rela dejan de estar disponibles, pero **sus registros están seguros** y siguen almacenados. Al
volver a suscribirse, todo se restablece exactamente como estaba. El propietario
aún puede eliminar un espacio de trabajo en pausa, y su período de gracia de 30
días para la eliminación se aplica como de costumbre.

## Créditos de IA

La asignación mensual de créditos del plan se restablece en cada período de
facturación. Los espacios de trabajo de pago pueden comprar **paquetes de créditos** de compra única desde la página de Facturación. Los créditos comprados
se gastan solo después de la asignación mensual y nunca vencen. La misma página
muestra cuántos créditos ha usado el espacio de trabajo en este período. Qué son
los créditos y cuánto cuestan los mensajes se explica en
[Créditos de IA y límites de uso](/es/help/ai-assistant/ai-credits-and-limits).

## Autoalojamiento en su lugar

Relaticle es de código abierto (AGPL-3.0). Las instalaciones autoalojadas son
gratuitas para siempre, sin suscripción ni pausa del espacio de trabajo, y la IA
funciona con sus propias claves de proveedor. El asistente no mide créditos allí.
Solo se aplica un límite de envío por minuto, que es 60 de forma predeterminada
y que el operador puede cambiar. Consulte la
[Guía de autoalojamiento](/es/developers/self-hosting).
