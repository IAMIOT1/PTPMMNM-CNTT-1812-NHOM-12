(() => {
    const storageKey = 'tms-language';
    const fallbackLanguage = 'vi';
    const supportedLanguages = ['vi', 'en'];
    const dictionaries = new Map();
    const scriptUrl = document.currentScript?.src || location.href;
    const dictionaryUrl = new URL('i18n.php', scriptUrl).href;
    let activeLanguage = fallbackLanguage;
    let autoTextKeys = new Map();
    let languageChangeToken = 0;

    const loadDictionary = async (language) => {
        if (dictionaries.has(language)) return dictionaries.get(language);
        const response = await fetch(`${dictionaryUrl}?lang=${encodeURIComponent(language)}`, {
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        });
        if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
            throw new Error(`Could not load the ${language} language dictionary.`);
        }
        const dictionary = await response.json();
        dictionaries.set(language, dictionary);
        return dictionary;
    };

    const readLanguage = () => {
        try {
            const language = localStorage.getItem(storageKey);
            return supportedLanguages.includes(language) ? language : fallbackLanguage;
        } catch (error) {
            console.error('Unable to read the saved TMS language preference.', error);
            return fallbackLanguage;
        }
    };

    const interpolate = (element, text, keyAttribute) => text.replace(/\{([a-z0-9_]+)\}/gi, (match, name) => {
        const value = element.getAttribute(`${keyAttribute}-${name}`);
        return value === null ? match : value;
    });

    const rememberAutoTextKeys = (dictionary) => {
        Object.entries(dictionary.__auto || {}).forEach(([text, key]) => autoTextKeys.set(text, key));
    };

    const translateAttributes = (root, dictionary) => {
        if (!root) return;
        const selectors = [
            ['data-i18n-aria-label', 'aria-label'],
            ['data-i18n-title', 'title'],
            ['data-i18n-placeholder', 'placeholder'],
            ['data-i18n-alt', 'alt']
        ];
        selectors.forEach(([keyAttribute, targetAttribute]) => {
            const nodes = [];
            if (root instanceof Element && root.hasAttribute(keyAttribute)) nodes.push(root);
            if (root.querySelectorAll) nodes.push(...root.querySelectorAll(`[${keyAttribute}]`));
            nodes.forEach((element) => {
                const entry = dictionary[element.getAttribute(keyAttribute)];
                const translated = typeof entry === 'string' ? interpolate(element, entry, keyAttribute) : entry;
                if (typeof translated === 'string' && element.getAttribute(targetAttribute) !== translated) {
                    element.setAttribute(targetAttribute, translated);
                }
            });
        });
    };

    const applyMarkedTranslations = (root, dictionary) => {
        if (!root) return;
        const nodes = [];
        if (root instanceof Element && root.hasAttribute('data-i18n')) nodes.push(root);
        if (root.querySelectorAll) nodes.push(...root.querySelectorAll('[data-i18n]'));
        nodes.forEach((element) => {
            const entry = dictionary[element.dataset.i18n];
            const translated = typeof entry === 'string' ? interpolate(element, entry, 'data-i18n') : entry;
            if (typeof translated === 'string' && element.textContent !== translated) {
                element.textContent = translated;
            }
        });

        translateAttributes(root, dictionary);
    };

    const logWorkspaceTranslations = (language, dictionary) => {
        const traces = [];
        document.querySelectorAll('[data-i18n^="workspace."]').forEach((element) => {
            const key = element.dataset.i18n;
            traces.push({
                key,
                value: dictionary[key],
                found: typeof dictionary[key] === 'string',
                element: element.tagName.toLowerCase(),
                attribute: 'text'
            });
        });
        [
            ['data-i18n-aria-label', 'aria-label'],
            ['data-i18n-title', 'title'],
            ['data-i18n-placeholder', 'placeholder'],
            ['data-i18n-alt', 'alt']
        ].forEach(([keyAttribute, targetAttribute]) => {
            document.querySelectorAll(`[${keyAttribute}^="workspace."]`).forEach((element) => {
                const key = element.getAttribute(keyAttribute);
                traces.push({
                    key,
                    value: dictionary[key],
                    found: typeof dictionary[key] === 'string',
                    element: element.tagName.toLowerCase(),
                    attribute: targetAttribute
                });
            });
        });

        console.log('[TMS i18n] Workspace translation trace', {
            language,
            dictionaryUrl: `${dictionaryUrl}?lang=${encodeURIComponent(language)}`,
            keys: traces
        });
        const missing = traces.filter((trace) => !trace.found);
        if (missing.length > 0) {
            console.warn('[TMS i18n] Workspace dictionary keys are missing.', missing);
        }
    };

    const excludedTextNode = (node) => {
        const parent = node.parentElement;
        if (!parent || parent.closest('[data-i18n], [data-i18n-ignore], script, style, textarea, noscript, code, [contenteditable="true"]')) {
            return true;
        }
        return false;
    };

    const markKnownText = (root, dictionary) => {
        if (autoTextKeys.size === 0 || !root) return;
        const textNodes = [];
        if (root instanceof Text) {
            textNodes.push(root);
        } else {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            while (walker.nextNode()) textNodes.push(walker.currentNode);
        }
        const candidates = [];
        textNodes.forEach((node) => {
            if (excludedTextNode(node)) return;
            const original = node.nodeValue || '';
            const label = original.trim();
            const key = autoTextKeys.get(label);
            if (!key || typeof dictionary[key] !== 'string') return;
            if (node.parentElement?.tagName === 'OPTION') {
                node.parentElement.dataset.i18n = key;
                return;
            }
            const start = original.indexOf(label);
            const range = document.createRange();
            range.setStart(node, start);
            range.setEnd(node, start + label.length);
            candidates.push({ range, key, translated: dictionary[key] });
        });

        candidates.reverse().forEach(({ range, key, translated }) => {
            const span = document.createElement('span');
            span.dataset.i18n = key;
            range.surroundContents(span);
            span.textContent = translated;
        });
    };

    const applyLanguage = async (language) => {
        const changeToken = ++languageChangeToken;
        const dictionary = await loadDictionary(language);
        if (changeToken !== languageChangeToken) return;
        activeLanguage = language;
        document.documentElement.lang = language;
        document.documentElement.dataset.language = language;
        markKnownText(document.body, dictionary);
        applyMarkedTranslations(document.body, dictionary);
        const title = document.querySelector('title[data-i18n]');
        if (title && typeof dictionary[title.dataset.i18n] === 'string') {
            document.title = dictionary[title.dataset.i18n];
        }
        document.querySelectorAll('[data-language-choice]').forEach((button) => {
            const selected = button.dataset.languageChoice === language;
            button.setAttribute('aria-pressed', String(selected));
            button.classList.toggle('is-selected', selected);
        });
        logWorkspaceTranslations(language, dictionary);
        document.dispatchEvent(new CustomEvent('tms:language-changed', { detail: { language, dictionary } }));
    };

    const observeDynamicContent = () => {
        const observer = new MutationObserver((mutations) => {
            const dictionary = dictionaries.get(activeLanguage);
            if (!dictionary) return;
            mutations.forEach((mutation) => {
                const changedNodes = mutation.type === 'characterData'
                    ? [mutation.target]
                    : Array.from(mutation.addedNodes);
                changedNodes.forEach((node) => {
                    if (!(node instanceof Element || node instanceof Text)) return;
                    markKnownText(node, dictionary);
                    applyMarkedTranslations(node instanceof Text ? node.parentElement : node, dictionary);
                });
            });
        });
        observer.observe(document.documentElement, { childList: true, characterData: true, subtree: true });
    };

    const initialize = async () => {
        const initialLanguage = readLanguage();
        const dictionary = await loadDictionary(initialLanguage);
        rememberAutoTextKeys(dictionary);
        await applyLanguage(readLanguage());
        observeDynamicContent();

        const otherLanguage = supportedLanguages.find((item) => item !== activeLanguage);
        if (otherLanguage) {
            loadDictionary(otherLanguage).then((otherDictionary) => {
                rememberAutoTextKeys(otherDictionary);
                const activeDictionary = dictionaries.get(activeLanguage);
                if (!activeDictionary) return;
                markKnownText(document.body, activeDictionary);
                applyMarkedTranslations(document.body, activeDictionary);
            }).catch((error) => {
                console.error(`Unable to load the ${otherLanguage} language dictionary.`, error);
            });
        }
    };

    document.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : event.target?.parentElement;
        const button = target?.closest('[data-language-choice]');
        if (!button) return;
        const language = button.dataset.languageChoice;
        if (!supportedLanguages.includes(language)) return;
        try {
            localStorage.setItem(storageKey, language);
            await applyLanguage(language);
        } catch (error) {
            console.error('Unable to apply the selected TMS language.', error);
        }
    });

    const initializeWhenReady = () => {
        initialize().catch((error) => {
            console.error('Unable to initialize TMS language.', error);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeWhenReady, { once: true });
    } else {
        initializeWhenReady();
    }
})();
