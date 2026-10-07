**Effective date:** October 6, 2026

This Privacy Policy explains how Relaticle ("we", "us", "our") collects, uses, and protects your personal data when you use our services.

---

## 1. What We Collect

### Cloud Users (app.relaticle.com)

- **Account information:** Name, email address, and password (hashed)
- **Profile data:** Avatar, workspace name, and role
- **CRM data:** Companies, people, opportunities, tasks, notes, and custom fields you create
- **Usage data:** Login timestamps, feature usage, and error reports
- **Technical data:** IP address, browser type, and device information

### Self-Hosted Users

Data from a self-hosted installation stays on your servers unless you configure an external integration. That integration may send authorized data to its provider.

### Website Visitors (relaticle.com)

- **Contact form submissions:** Name, email, and message content
- **Analytics:** Anonymous page views and referrer data

## 2. How We Use Your Data

We use your data to:

- Provide and maintain the CRM service
- Authenticate your account and enforce workspace-level access controls
- Send transactional emails (password resets, workspace invitations)
- Send product updates to the email address on a verified account. You can unsubscribe from them at any time
- Improve the service based on aggregated, anonymized usage patterns
- Respond to support inquiries

We do **not**:

- Sell your data to third parties
- Use your CRM data for advertising
- Share your data with third parties except as described below
- Train AI models on your data

## 3. Third-Party Services

The Cloud service uses the following third-party providers:

- **Hosting infrastructure:** For application and database hosting
- **Email delivery:** For transactional emails (password resets, invitations)
- **Error monitoring:** For detecting and fixing bugs. An error report can include data from the request that failed
- **AI providers:** For AI features you use, such as the assistant and email thread summaries. They receive only the content needed to answer that request.
- **Payments:** For Cloud plan billing. Card details go to the payment provider directly
- **Analytics:** For page view counts on the website and in the app, without cookies
- **Product updates:** For the update emails described in section 2

The [Security page](/security#providers) names each provider and what it receives.

Relaticle does not sell CRM data. Relaticle does not use CRM data for advertising. Relaticle does not train AI models on CRM data.

## 4. Data Security

We protect your data with:

- Encrypted connections (TLS/HTTPS) for all data in transit
- Encrypted database storage for sensitive fields
- Workspace-based access isolation (multi-tenancy)
- API token authentication with scoped permissions
- Regular security updates and dependency audits

## 5. Data Retention

- **Active accounts:** Data is retained as long as your account is active
- **Scheduled account deletions:** Accounts and their personal data are removed after a 30-day grace period. Records in shared workspaces remain.
- **Contact form submissions:** Retained for up to 12 months
- **Server logs:** Retained for up to 90 days

## 6. Your Rights

You have the right to:

- **Access** your personal data at any time through the application
- **Export** your data via the application or REST API
- **Correct** inaccurate personal data through your profile settings
- **Request deletion** of your account and personal data
- **Object** to data processing for specific purposes

To exercise these rights, email privacy@relaticle.com or use [Contact Us](/contact). We will respond within 15 business days.

To request account deletion, email privacy@relaticle.com or contact us. If **Delete Account** is available in your profile settings, you can schedule deletion there.

## 7. Cookies

The Cloud service uses essential cookies for:

- Session management (keeping you logged in)
- CSRF protection (security)
- Theme preferences (light/dark mode)

We do not use tracking cookies or third-party advertising cookies.

## 8. Children

Our services are not directed to children under 16. We do not knowingly collect personal data from children.

## 9. AI Connectors / MCP Server

You can authorize an MCP client or AI provider to access your CRM data. The provider receives only data requested through authorized tools. The provider processes that data under its own terms and privacy policy. Disconnecting the provider or revoking its token stops future access.

Relaticle enforces workspace and token scope on every tool request.

**Data tool responses can include:**

- User names, email addresses, and identifiers.
- Workspace names and identifiers.
- Workspace-member names, emails, and identifiers.
- Token ability names.
- Companies, people, opportunities, tasks, and notes.
- Record identifiers and canonical record URLs.
- Contact details.
- Custom-field definitions, options, and values.
- Relationships between records.
- Opportunity stages and amounts.
- Activity actors, field changes, and timestamps.
- Record creation and update timestamps.
- Pagination and count metadata.

**What the connector can write.** MCP write tools can change CRM records. They can create, update, delete, and link or unlink companies, people, opportunities, tasks, and notes. Task assignment operations can send transactional notifications.

**OAuth tokens.** When you connect via OAuth (Claude Connectors Directory, ChatGPT App Directory), Relaticle stores an access token and refresh token in the `oauth_access_tokens` and `oauth_refresh_tokens` tables. Access tokens expire after 30 days and refresh tokens after 90 days. You can revoke any connector at any time from **Settings → Access Tokens → AI Connectors**; revocation immediately invalidates both the access and refresh token.

**Personal access tokens.** If you connect using a personal access token created from the Access Tokens page, you control its lifetime. Tokens are hashed at rest. You can revoke individual tokens at any time.

**Conversation data.** The MCP server does not log, store, or process the conversation context of your AI assistant. It only sees the specific tool arguments your assistant sends and the records it requests.

**Response metadata.** Tool responses can include record identifiers, timestamps, pagination metadata, and count metadata.

Tool responses exclude:

- Access tokens.
- Refresh tokens.
- Passwords.
- API keys.
- Authentication secrets.

## 10. Email and Calendar Integration

You can connect a Google (Gmail and Google Calendar) or Microsoft account to a workspace. This section explains what Relaticle does with the data it receives from that account.

**What we access.** With your permission, Relaticle reads:

- Email messages: sender, recipients, subject, body, timestamps, and thread information.
- Attachment names, types, and sizes. A file stays with your provider, and Relaticle downloads it only when you open it, without storing it. Images embedded in a message and small files that arrive inside it are stored with that message.
- Events on your primary calendar: title, description, location, times, organizer, and attendees with their responses.

**How we use it.** We use this data only to provide features you can see in Relaticle:

- Show emails and meetings on the companies, people, and opportunities they involve, and let you read and search them there.
- Create company and people records from email participants. By default, this happens only for addresses your workspace has emailed. Workspace admins can change or turn this off.
- Send email you write in Relaticle from your own address. We send only when you click Send or schedule the message.
- Accept or decline a meeting invitation when you choose to. This changes only your own response.
- Summarize an email thread when you ask. The thread content goes to our AI provider to produce that summary. The summary is saved with the thread in your workspace.

We never change, label, or delete messages in your mailbox.

**Who can see it.** Your sharing settings decide what teammates see: nothing, participants and timestamps, the subject line, or the full email. Workspace blocklists and protected contacts hide matching emails and meetings from everyone. We don't share it outside your workspace, except with the service providers listed in section 3.

**What we don't do.** We don't sell this data. We don't use it for advertising. We don't use it to train AI models, and the AI provider that writes a summary does not train its models on the thread. We don't build aggregated or anonymized data sets from it. Relaticle staff do not read it, except with your permission, for security, or where the law requires.

**Disconnecting and deletion.** Disconnecting an account stops the sync and deletes the stored access tokens. For Google accounts, it also revokes Relaticle's access, unless another of your workspaces still uses that connection. Emails and meetings already synced stay in the workspace. They are removed when you delete your Relaticle account, after the 30-day grace period. To delete synced data sooner, email privacy@relaticle.com. You can also revoke access at any time from your Google or Microsoft account settings.

**Security.** Access tokens are encrypted at rest. All data travels over encrypted connections.

**Google API Services.** Relaticle's use and transfer of information received from Google APIs to any other app will adhere to the [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy), including the Limited Use requirements. This covers the data as received and anything derived from it, such as a thread summary.

## 11. Changes to This Policy

We may update this Privacy Policy from time to time. We will notify registered users of material changes via email or in-app notification.

## 12. Contact

Questions about this Privacy Policy? Email privacy@relaticle.com or reach us at [Contact Us](/contact).
