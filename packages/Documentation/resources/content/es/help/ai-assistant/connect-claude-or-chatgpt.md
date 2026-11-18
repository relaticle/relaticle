---
title: Conectar Claude o ChatGPT a Relaticle
description: Configure un conector de IA con Relaticle mediante OAuth. Esta guía explica el acceso y por qué los clientes MCP escriben directamente en los registros.
order: 5
updated: "2026-10-05"
related: [docs/guides/mcp, help/ai-assistant/ask-questions-about-your-data, help/ai-assistant/ai-credits-and-limits]
---

Los asistentes que ya usa, Claude y ChatGPT, pueden trabajar directamente con
sus datos de Relaticle. Instale el plugin de Relaticle en ChatGPT, o agregue
Relaticle como **conector** en Claude, apruebe el acceso una vez y, a partir de
ahí, podrá preguntarle a su asistente sobre su pipeline, o pedirle que cree y
actualice registros, desde su propio chat.

Sin código y sin claves de API: toda la configuración es una pantalla de
consentimiento.

## Conéctelo

1. Agregue Relaticle a su asistente:
   - **ChatGPT:** abra el
     [plugin de Relaticle](https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961)
     en el directorio de plugins de ChatGPT y haga clic en **Install plugin**.
   - **Claude:** en **Settings → Connectors**, agregue un conector personalizado
     con la URL `https://mcp.relaticle.com`.
2. El asistente lo envía a Relaticle para que inicie sesión, si aún no lo ha hecho.
3. Apruebe el acceso y **elija el espacio de trabajo** que el conector puede usar.
4. Listo. Pregúntele algo a su asistente: "¿qué hay en mi pipeline este mes?"
   En ChatGPT, empiece el mensaje con **@Relaticle**.

El conector actúa como **usted**, dentro de **ese único espacio de trabajo**:
puede hacer lo que usted puede hacer allí, y nada más. Para dirigir un asistente
a otro espacio de trabajo, revoque el conector y conéctelo de nuevo.

## Qué puede hacer su asistente

Buscar y leer empresas, personas, oportunidades, tareas y notas; crearlas,
actualizarlas y eliminarlas; asociar tareas y notas a registros; y leer los
atributos personalizados de su espacio de trabajo para que sus respuestas usen
su esquema real.

Algo que debe saber: los cambios hechos mediante un conector se aplican
directamente. La tarjeta de revisión del chat integrado de Relaticle no existe
aquí, así que confíe en las confirmaciones de su propio asistente antes de que
actúe. La actividad del conector nunca gasta los
[créditos de IA](/es/help/ai-assistant/ai-credits-and-limits) de su espacio de
trabajo, que son solo para Rela, el asistente integrado.

## Vea y revoque conexiones

Abra **Configuración → Tokens de acceso** desde el menú de su avatar. La sección
**Conectores de IA** enumera cada asistente conectado a sus espacios de trabajo,
a qué espacio de trabajo está vinculado cada uno y cuántos tokens activos tiene.
**Revocar** corta el acceso de un asistente de inmediato y detiene cualquier
correo que haya puesto en cola y que aún no haya salido. Siempre puede
conectarlo de nuevo más adelante.

![La lista Conectores de IA con Claude y ChatGPT vinculados a un espacio de trabajo, con el número de tokens activos y un botón de revocar en cada fila](/help-assets/ai-assistant/connect-claude-or-chatgpt-1.png)

## Conviene saber

- Funciona con un espacio de trabajo activo de Relaticle Cloud. Un espacio de
  trabajo en pausa no se puede seleccionar en la pantalla de consentimiento hasta
  que vuelva a tener una suscripción.
- Claude y ChatGPT son hoy los asistentes con un flujo de conexión integrado,
  pero el servidor usa el estándar abierto MCP, así que cualquier otra
  herramienta compatible con MCP puede usar la misma URL con un token de acceso
  personal, como los desarrolladores conectan Cursor (consulte la
  [guía del MCP server](/es/developers/mcp)).
- Las instalaciones autoalojadas incluyen el mismo servidor de conectores en su
  propio dominio. La [guía del MCP server](/es/developers/mcp) explica la
  configuración.
- El acceso se renueva solo en segundo plano. Solo volverá a ver la pantalla de
  consentimiento si revoca y vuelve a conectar.
