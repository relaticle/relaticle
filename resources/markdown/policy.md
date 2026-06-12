# Privacy Policy

**Effective date:** March 20, 2026

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

We do not collect any data from self-hosted installations. Your data stays entirely on your servers.

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

We do not share your CRM data with any third party.

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

When you connect Relaticle to Claude, ChatGPT, or any other Model Context Protocol (MCP) client, the connector accesses the same CRM data you can already see in your account.

**What the connector can read.** Companies, people, opportunities, tasks, notes, custom-field values, and team-member metadata for the team you authorize. The connector cannot read other teams' data or any user files outside the CRM.

**What the connector can write.** Create, update, delete, and link/unlink companies, people, opportunities, tasks, and notes — the same actions you can perform in the Relaticle UI. Writes are scoped to the team you authorize.

**OAuth tokens.** When you connect via OAuth (Claude Connectors Directory, ChatGPT App Directory), Relaticle stores an access token and refresh token in the `oauth_access_tokens` and `oauth_refresh_tokens` tables. You can revoke any connector at any time from your account settings; revocation immediately invalidates the connector.

**Personal access tokens.** If you connect using a personal access token created from the Access Tokens page, you control its lifetime. Tokens are hashed at rest. You can revoke individual tokens at any time.

**Conversation data.** The MCP server does not log, store, or process the conversation context of your AI assistant. It only sees the specific tool arguments your assistant sends and the records it requests.

**Telemetry.** Relaticle does not include telemetry, request IDs, internal timestamps, or session identifiers in MCP tool responses. Tool outputs are limited to fields documented in the public MCP guide.

## 10. Changes to This Policy

We may update this Privacy Policy from time to time. We will notify registered users of material changes via email or in-app notification.

## 11. Contact

Questions about this Privacy Policy? Reach us at [Contact Us](/contact).
