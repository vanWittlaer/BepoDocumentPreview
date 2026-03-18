# BepoDocumentPreview

> **Development tool only. Do not install on production or staging environments.**

Interactive browser tool for previewing Shopware 6 document templates (invoice, delivery note, credit note, storno) without creating actual documents.

## Problem

Testing Shopware document templates requires generating a real document for a real order in the Admin panel. Every template change means repeating this process, and the generated documents pollute the order history.

## Solution

A split-view browser UI that renders document templates on the fly using real order data, without persisting anything. Edit a Twig template, hit Render, see the result instantly.

## Installation

```bash
ddev exec bin/console plugin:refresh
ddev exec bin/console plugin:install BepoDocumentPreview --activate
ddev exec bin/console cache:clear
```

## Usage

1. Open `https://<your-shop>/api/_action/bepo-document-preview/ui`
2. Log in with your Shopware admin credentials
3. Search and select an order
4. Choose a document type (invoice, delivery note, credit note, storno)
5. Edit the document config JSON if needed (auto-loaded from DB)
6. Click **Render PDF** or **Render HTML**

**Keyboard shortcut:** `Ctrl/Cmd+Enter` triggers PDF rendering.

The HTML view is useful for fast iteration — it renders quicker and you can inspect the markup with browser dev tools.

## How it works

The plugin bypasses the standard `DocumentGenerator` and renderer decorator chain entirely. Instead it:

1. Loads the order with all associations via `OrderDocumentCriteriaFactory`
2. Loads the document config from the database via `DocumentConfigLoader`
3. Merges your JSON overrides into the config
4. Renders the Twig template directly via `DocumentTemplateRenderer`
5. Converts HTML to PDF via Dompdf (for PDF output)

This means no "document already exists" errors from plugins like Pickware ERP, no document numbers consumed, and no documents attached to orders.

## Config JSON

When you select an order and document type, the full document configuration is loaded from the database into the JSON editor. You can override any field:

| Field | Description |
|---|---|
| `documentNumber` | Document number shown on the document |
| `documentDate` | Document date |
| `companyName`, `companyStreet`, etc. | Company address block |
| `taxNumber`, `vatId` | Tax identifiers |
| `bankName`, `bankIban`, `bankBic` | Bank details in footer |
| `executiveDirector` | Director name in footer |
| `displayPrices` | Show/hide prices |
| `displayFooter` | Show/hide footer |
| `displayLineItems` | Show/hide line items |
| `displayPageCount` | Show/hide page numbers |
| `pageSize` | Paper size (`a4`, `letter`, etc.) |
| `pageOrientation` | `portrait` or `landscape` |
| `custom` | Object with type-specific fields (`invoiceNumber`, `stornoNumber`, etc.) |

## Security

**This plugin is intended for local development only and must not be deployed to production or staging environments.**

- The UI page (`/ui`) is publicly accessible but contains no server-side data — it's a static HTML/JS shell.
- All data endpoints require a valid admin API OAuth token.
- The plugin exposes order data and document rendering capabilities behind admin authentication, but the additional attack surface is unnecessary outside of development.

## Supported document types

All standard Shopware document types are supported, plus any custom types that follow the `@Framework/documents/<type>.html.twig` template convention.
