---
title: Créditos de IA y límites de uso
description: Cómo gastan créditos los mensajes al asistente en Relaticle Cloud, qué incluye cada plan y qué pasa al llegar a cero. Autoalojado no tiene límite de créditos.
order: 4
updated: "2026-11-04"
related: [help/ai-assistant/choose-an-ai-model, help/workspace/billing-and-plans]
---

En Relaticle Cloud, cada mensaje que envía al asistente consume créditos de la
asignación mensual de su espacio de trabajo. Una respuesta simple cuesta un
crédito, y cada herramienta que el asistente usa para responder suma más. Los
créditos los comparte todo el espacio de trabajo, no cada persona.

## Instalaciones autoalojadas

Una instalación autoalojada no tiene límite de créditos. El asistente funciona
con su propia clave de proveedor o con un modelo local, y usted le paga
directamente a ese proveedor. Solo rige el límite de envíos por minuto, como se
explica en Límites de uso más abajo.

## Cuánto cuesta un mensaje

En Relaticle Cloud, el costo depende del multiplicador del modelo (consulte
[Elegir un modelo de IA](/es/help/ai-assistant/choose-an-ai-model)) más un pequeño cargo adicional por cada herramienta que el asistente usa al responder. Buscar
registros, leer una empresa y redactar una propuesta cuentan cada uno. Una
pregunta simple con el modelo predeterminado cuesta 1 crédito. Una solicitud de
varios pasos con un modelo 3x cuesta más. Cada búsqueda web suma 2 créditos
(consulte
[Investigar una empresa con Rela](/es/help/ai-assistant/research-a-company-with-rela)).
El costo final se define cuando termina la respuesta.

## Qué incluye cada plan

| Plan | Créditos por mes | Mensajes por minuto |
|------|------------------|---------------------|
| Cloud Pro | 2,000 | 30 |
| Prueba de Cloud Pro | 300 | 30 |
| Cloud Scale | 5,000 | 60 |
| Enterprise | Acordado con su equipo | Acordado con su equipo |
| Autoalojado | Sin medición | 60, definido por `CHAT_RATE_LIMIT_PER_MINUTE` (0 para quitar el límite) |

Relaticle Cloud no tiene un plan gratuito. Lo que cuesta cada plan está en
[Facturación y planes](/es/help/workspace/billing-and-plans).

Las asignaciones de Cloud se restablecen con su período de facturación. Los
espacios de trabajo de pago también pueden comprar paquetes de créditos. Los
créditos comprados solo se usan después de agotar la asignación mensual y
**nunca vencen**. El saldo restante del período aparece en la página
**Facturación**, debajo del nombre de su espacio de trabajo.

## Cuando se acaban los créditos

En Relaticle Cloud, el chat muestra **Ha usado todos sus créditos de IA** con la
fecha de restablecimiento, y los mensajes nuevos esperan hasta entonces. Nada
más en Relaticle se ve afectado. Los espacios de trabajo de pago tienen un botón
**Agregar créditos** en el mismo aviso. El resto del CRM nunca gasta créditos:
solo los mensajes al asistente.

## Límites de uso

El límite de mensajes por minuto se comparte en todo el espacio de trabajo. Si
su equipo envía más rápido, el chat muestra **Envío demasiado rápido** con una
cuenta regresiva y envía su mensaje automáticamente cuando se abre la ventana,
así que no se pierde nada. Un mensaje puede tener hasta 5,000 caracteres.

En una instalación autoalojada, el límite es de 60 mensajes por minuto de forma
predeterminada. El operador lo define con `CHAT_RATE_LIMIT_PER_MINUTE`, y 0 lo
quita.
