---
title: Investigar una empresa con Rela
description: Deje que Rela busque en la web una empresa o persona que aún no está en su espacio de trabajo, y elija cuándo puede hacerlo.
order: 6
updated: "2026-11-04"
related: [help/ai-assistant/approve-what-the-assistant-changes, help/ai-assistant/ai-credits-and-limits, help/ai-assistant/choose-an-ai-model]
---

Rela, el asistente de IA integrado, responde primero con lo que hay en su
espacio de trabajo. Cuando una pregunta necesita algo que sus registros no
tienen, como qué vende una empresa o quién la fundó, Rela puede buscar en la web
y leer las páginas que encuentra.

## Cómo funciona una solicitud de investigación

Pida en palabras simples, por ejemplo "Investigue Filaforms y dígame si podrían
ser un buen cliente para nosotros". Cuando Rela necesita la web, pregunta antes
de buscar. Una tarjeta sobre el cuadro de mensaje muestra qué quiere buscar y
por qué, con tres botones:

- **Permitir** deja que Rela busque en la web solo para esta solicitud. Su siguiente mensaje vuelve a preguntar.
- **Permitir siempre** hace lo mismo y deja de preguntar a partir de entonces.
- **Denegar** mantiene a Rela en los datos de su espacio de trabajo. Responde con sus registros y dice que no usó la web.

Mientras Rela trabaja, el chat muestra qué está buscando y qué página está
leyendo. La respuesta termina con una fila de **Fuentes**: un enlace por cada
página que Rela citó, que se abre en una pestaña nueva.

Rela nunca escribe por su cuenta en sus registros lo que encontró. Cuando sugiere
agregar una nota o completar un campo, usted aprueba ese cambio igual que
cualquier otro. Consulte
[Aprobar los cambios del asistente](/es/help/ai-assistant/approve-what-the-assistant-changes).

## Elija cuándo puede buscar Rela

Abra **Configuración** desde el menú de su avatar, luego **Rela**, y en **Búsqueda web** elija una opción:

| Opción | Qué sucede |
|--------|--------------|
| Preguntar siempre | Rela pregunta antes de cada solicitud de investigación. Es el valor predeterminado. |
| Permitir siempre | Rela busca sin preguntar. |
| No permitir | Rela nunca busca en la web y responde solo con su espacio de trabajo. |

La elección es solo suya. Sus compañeros eligen la suya.

## Desactive la búsqueda web para todo el espacio de trabajo

El propietario del espacio de trabajo puede desactivar la búsqueda web para todos
los miembros en **Configuración del espacio de trabajo** (haga clic en el nombre
de su espacio de trabajo en la barra lateral), en la sección **Rela**. Mientras
esté desactivada, Rela nunca pide buscar, y el ajuste de cada miembro indica que
el propietario la desactivó.

## Qué sale de su espacio de trabajo

Rela escribe las consultas de búsqueda por su cuenta, y van a la búsqueda web del
proveedor de IA: Anthropic para los modelos Claude, OpenAI para los modelos GPT.
Una consulta puede nombrar una empresa o una persona de su solicitud. Las páginas
que Rela lee las obtiene el mismo proveedor, que ya procesa cada mensaje que
usted envía a Rela.

Las páginas de la web no son instrucciones. Rela trata lo que lee como
información, y cualquier cambio que sugiera sigue esperando su aprobación.

## Cuánto cuesta una búsqueda

En Relaticle Cloud, cada búsqueda web usa 2 créditos además del costo normal del
mensaje. Leer una página cuenta como cualquier otra herramienta. Consulte
[Créditos de IA y límites de uso](/es/help/ai-assistant/ai-credits-and-limits).

## Qué modelos pueden buscar

Solo los modelos Claude y GPT pueden buscar en la web. Rela nunca pide buscar
mientras hay otro modelo seleccionado, como Gemini o un modelo autoalojado
(Ollama o un endpoint compatible con OpenAI). Elija un modelo Claude o GPT en el
selector de modelo para investigar una empresa. Consulte
[Elegir un modelo de IA](/es/help/ai-assistant/choose-an-ai-model).
