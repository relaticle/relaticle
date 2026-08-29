# Privacy Policy

**Effective date:** August 26, 2026

This Privacy Policy explains how Relaticle ("we", "us", "our") collects, uses, and protects your personal data when you use our services.

---

## 1. What We Collect

### Cloud Users (app.relaticle.com)

- **Account information:** Name, email address, and password (hashed)
- **Profile data:** Avatar, team name, and role
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
- Authenticate your account and enforce team-level access controls
- Send transactional emails (password resets, team invitations)
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
- **Error monitoring:** For detecting and fixing bugs (anonymized error reports)

Relaticle does not sell CRM data. Relaticle does not use CRM data for advertising. Relaticle does not train AI models on CRM data.

## 4. Data Security

We protect your data with:

- Encrypted connections (TLS/HTTPS) for all data in transit
- Encrypted database storage for sensitive fields
- Team-based access isolation (multi-tenancy)
- API token authentication with scoped permissions
- Regular security updates and dependency audits

## 5. Data Retention

- **Active accounts:** Data is retained as long as your account is active
- **Deleted accounts:** Data is deleted within 30 days of account deletion
- **Contact form submissions:** Retained for up to 12 months
- **Server logs:** Retained for up to 90 days

## 6. Your Rights

You have the right to:

- **Access** your personal data at any time through the application
- **Export** your data via the application or REST API
- **Correct** inaccurate personal data through your profile settings
- **Delete** your account and associated data
- **Object** to data processing for specific purposes

To exercise these rights, contact us at [Contact Us](/contact). We will respond within 15 business days.

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
- Team names and identifiers.
- Team-member names, emails, and identifiers.
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

## 10. Changes to This Policy

We may update this Privacy Policy from time to time. We will notify registered users of material changes via email or in-app notification.

## 11. Contact

Questions about this Privacy Policy? Reach us at [Contact Us](/contact).
