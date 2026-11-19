---
title: Configure la privacidad del correo
description: Los propietarios y administradores ocultan el correo de direcciones protegidas o bloqueadas y deciden qué registros crea la sincronización.
order: 6
updated: "2026-11-08"
related: [help/email-and-calendar/choose-who-sees-your-email, help/workspace/manage-members-and-roles, help/email-and-calendar/connect-your-google-account]
---

Los propietarios y administradores definen las reglas de correo de todo el
espacio de trabajo: las direcciones cuyo correo permanece oculto y los registros
que crea la sincronización. Abra **Configuración del espacio de trabajo** y luego
**Correo electrónico y calendario**. Las dos pestañas siguientes aparecen solo
para esos dos roles. Cuánto de cada buzón ve el espacio de trabajo se define en
el propio buzón, como explica
[Elija quién ve su correo](/es/help/email-and-calendar/choose-who-sees-your-email).

## Oculte el correo que involucra ciertas direcciones

La pestaña **Visibilidad del correo** oculta los correos y eventos del calendario
que involucran las direcciones y dominios que usted enumere, en todo Relaticle.

1. Haga clic en **Agregar contactos**.
2. Ingrese **Direcciones de correo electrónico** o **Dominios**, y presione Enter
   después de cada uno.
3. Marque **Incluir subdominios** para cubrir direcciones como
   `user@mail.example.com` cuando agregue `example.com`.
4. Haga clic en **Enviar**.

Cada entrada tiene un nivel de aplicación, que se cambia con **Cambiar nivel de
aplicación**.

- **Protegido**: se oculta solo cuando todas las direcciones del correo o evento
  están protegidas o bloqueadas. Las conversaciones internas siguen siendo
  privadas, y una conversación con un cliente se sigue mostrando.
- **Bloqueado**: se oculta siempre que esa dirección esté en el correo o evento.

Las direcciones de los propios miembros del espacio de trabajo están protegidas
de forma predeterminada, y también sus dominios de trabajo. Esas entradas dicen
**Predeterminado del sistema** y siempre se aplican.

![La pestaña Visibilidad del correo con direcciones y dominios protegidos, su nivel de aplicación y quién los agregó](/help-assets/email-and-calendar/set-workspace-email-privacy-1.png)

## Decida qué registros crea la sincronización

La pestaña **Creación de registros** define qué ocurre cuando un correo o evento
sincronizado involucra a alguien que aún no está en su CRM.

| Modo de creación de contactos | Qué se crea |
|---|---|
| **Todos los contactos** | Un registro para cada persona que aparece en los correos y eventos del calendario de los miembros. |
| **Creación selectiva de contactos** | Un registro solo para quien un miembro escribió un correo, o que aparece en los eventos del calendario de un miembro. |
| **Ninguno** | Nada. Los correos y eventos se siguen vinculando con los registros que usted crea. |

**Creación selectiva de contactos** es el modo recomendado y con el que comienza
un espacio de trabajo nuevo. Evita que los boletines y los correos entrantes
no solicitados llenen su CRM.

**Crear registros de empresa automáticamente** agrega una empresa a partir del
dominio de correo de una persona nueva. Los dominios de correo gratuitos, como
gmail.com, nunca crean una empresa, y los remitentes automáticos, como las
direcciones no-reply, nunca crean un registro.

Un cambio aquí afecta solo al correo y a los eventos que se sincronicen después.
