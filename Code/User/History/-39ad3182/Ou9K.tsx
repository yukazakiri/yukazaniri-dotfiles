
import "@/echo"; // Initialize Laravel Echo for real-time
import { Toaster } from "@/components/ui/sonner";
import { ThemeProvider } from "@/hooks/use-theme";
import { createInertiaApp } from "@inertiajs/react";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createRoot, hydrateRoot } from "react-dom/client";

createInertiaApp({
    title: (title) => {
        // Use dynamic appName from shared props, fallback to VITE_APP_NAME
        const appName = (window as any).appName || import.meta.env.VITE_APP_NAME || "DCCP HUB";
        return title ? `${title} - ${appName}` : appName;
    },
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob("./pages/**/*.tsx")),
    setup({ el, App, props }) {
        const inertiaApp = (
            <ThemeProvider defaultTheme="dark" storageKey="vite-ui-theme">
                <App {...props} />
                
            </ThemeProvider>
        );

        if (el.hasChildNodes()) {
            hydrateRoot(el, inertiaApp);
            return;
        }

        createRoot(el).render(inertiaApp);
    },
    progress: {
        color: "#4B5563",
    },
}).then();
