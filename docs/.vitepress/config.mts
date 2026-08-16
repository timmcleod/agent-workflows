import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

export default withMermaid(defineConfig({
    title: 'Agent Workflows',
    description: 'Durable, resumable, human-interruptible agent workflows for the Laravel AI SDK.',
    base: '/agent-workflows/',
    cleanUrls: true,

    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/agent-workflows/logo.svg' }],
    ],

    // The docs hub doubles as the site's landing page.
    rewrites: {
        'README.md': 'index.md',
    },

    // The package's prompt templates use {{ }}, which Vue claims as
    // interpolation syntax. Scope the protection to markdown content only
    // (fenced blocks are already v-pre by default; this covers inline code)
    // so the theme's own components keep their interpolation.
    markdown: {
        // Code blocks stay dark in both modes.
        theme: {
            light: 'one-dark-pro',
            dark: 'one-dark-pro',
        },

        config(md) {
            const original = md.renderer.rules.code_inline!
            md.renderer.rules.code_inline = (tokens, idx, options, env, self) =>
                original(tokens, idx, options, env, self).replace(/^<code/, '<code v-pre')
        },
    },

    themeConfig: {
        logo: { light: '/logo.svg', dark: '/logo-dark.svg' },

        nav: [
            { text: 'Quick Start', link: '/quick-start' },
            { text: 'Packagist', link: 'https://packagist.org/packages/timmcleod/agent-workflows' },
        ],

        sidebar: [
            {
                text: 'Start Here',
                items: [
                    { text: 'Quick Start', link: '/quick-start' },
                    { text: 'Five Patterns, Made Durable', link: '/five-patterns-made-durable' },
                    { text: 'How It Works', link: '/how-it-works' },
                ],
            },
            {
                text: 'Building Workflows',
                items: [
                    { text: 'Defining Workflows', link: '/defining-workflows' },
                    { text: 'Agent Steps', link: '/agent-steps' },
                    { text: 'Workflow State', link: '/workflow-state' },
                    { text: 'Human in the Loop', link: '/human-in-the-loop' },
                    { text: 'Agent Debates', link: '/agent-debate' },
                ],
            },
            {
                text: 'Running in Production',
                items: [
                    { text: 'Runs & Observability', link: '/runs-and-observability' },
                    { text: 'Testing', link: '/testing' },
                    { text: 'Operations', link: '/operations' },
                ],
            },
        ],

        search: {
            provider: 'local',
        },

        socialLinks: [
            { icon: 'github', link: 'https://github.com/timmcleod/agent-workflows' },
        ],

        editLink: {
            pattern: 'https://github.com/timmcleod/agent-workflows/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        outline: { level: [2, 3] },

        footer: {
            message: 'Released under the MIT License.',
        },
    },

    mermaid: {
        theme: 'base',
        themeVariables: {
            fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
            fontSize: '14px',
            primaryColor: '#eff6ff',
            primaryBorderColor: '#2563eb',
            primaryTextColor: '#18181b',
            lineColor: '#71717a',
            secondaryColor: '#f4f4f5',
            tertiaryColor: '#fafafa',
            actorBorder: '#2563eb',
            actorBkg: '#eff6ff',
            noteBkgColor: '#fef3c7',
            noteBorderColor: '#d97706',
            signalColor: '#3f3f46',
            signalTextColor: '#3f3f46',
            labelBoxBkgColor: '#f4f4f5',
        },
        flowchart: { curve: 'basis' },
        sequence: { mirrorActors: false },
    },
}))
