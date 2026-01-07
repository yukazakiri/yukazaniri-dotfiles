export type ColorTheme = "default" | "amber" | "amethyst" | "caffeine" | "graphite" | "nature"

export interface ThemeConfig {
    id: ColorTheme
    name: string
    description: string
    colors: {
        primary: string
        secondary: string
        accent: string
    }
}

export const themes: ThemeConfig[] = [
    {
        id: "default",
        name: "Default",
        description: "The classic look.",
        colors: {
            primary: "oklch(0.6850 0.1690 237.3230)",
            secondary: "oklch(0.9514 0.0250 236.8242)",
            accent: "oklch(0.9505 0.0507 163.0508)",
        }
    },
    {
        id: "amber",
        name: "Amber Minimal",
        description: "Warm and inviting with a clean aesthetic.",
        colors: {
            primary: "oklch(0.7686 0.1647 70.0804)",
            secondary: "oklch(0.9670 0.0029 264.5419)",
            accent: "oklch(0.9869 0.0214 95.2774)",
        }
    },
    {
        id: "amethyst",
        name: "Amethyst Haze",
        description: "Deep purples for a creative vibe.",
        colors: {
            primary: "oklch(0.6104 0.0767 299.7335)",
            secondary: "oklch(0.8957 0.0265 300.2416)",
            accent: "oklch(0.7889 0.0802 359.9375)",
        }
    },
    {
        id: "caffeine",
        name: "Caffeine",
        description: "Monochromatic tones for focus.",
        colors: {
            primary: "oklch(0.4891 0 0)",
            secondary: "oklch(0.9067 0 0)",
            accent: "oklch(0.8078 0 0)",
        }
    },
    {
        id: "graphite",
        name: "Graphite",
        description: "Bold and professional.",
        colors: {
            primary: "oklch(0.4341 0.0392 41.9938)",
            secondary: "oklch(0.9200 0.0651 74.3695)",
            accent: "oklch(0.9310 0 0)",
        }
    },
    {
        id: "nature",
        name: "Nature",
        description: "Earthy tones for a calming effect.",
        colors: {
            primary: "oklch(0.5234 0.1347 144.1672)",
            secondary: "oklch(0.9571 0.0210 147.6360)",
            accent: "oklch(0.9000 0.0500 90.0000)",
        }
    }
]
