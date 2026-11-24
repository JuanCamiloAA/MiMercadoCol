// Busca palabras sin tilde (o sin eñe) en los textos que ve la gente y corrige las seguras.
//
//   node scripts/tildes.mjs           informa; sale con 1 si queda algo por corregir
//   node scripts/tildes.mjs --list    lo mismo, con cada hallazgo (archivo:línea)
//   node scripts/tildes.mjs --fix     corrige lo seguro en resources/js y lista lo que queda
//
// Los .ts y .tsx se leen con el compilador de TypeScript: solo se miran textos (JSX y cadenas),
// nunca nombres, claves de objeto, rutas, clases ni valores que viajan al servidor. Las vistas
// Blade (recibos, PDF) se revisan pero no se corrigen solas: son pocas y van a mano.
//
// Una cadena que es código y contiene una palabra del diccionario va en ALLOW, o se marca en su
// línea con el comentario `tildes-ok`. «esta», «publica», «autorizo»… son correctas en algunos
// usos: esas se avisan como dudosas, no hacen fallar el script y no se corrigen solas.
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, sep } from 'node:path';
import process from 'node:process';
import ts from 'typescript';

const fix = process.argv.includes('--fix');
const list = fix || process.argv.includes('--list');

// --- Diccionario -----------------------------------------------------------------------------

/** En la interfaz siempre llevan tilde o eñe. */
const SAFE = {
    accion: 'acción', actualizacion: 'actualización', ademas: 'además', agoto: 'agotó', ahi: 'ahí', algun: 'algún',
    alla: 'allá', alli: 'allí', almacen: 'almacén', ano: 'año', anos: 'años', anoto: 'anotó', anulacion: 'anulación',
    aparecera: 'aparecerá', aplicacion: 'aplicación', aqui: 'aquí', articulo: 'artículo', articulos: 'artículos',
    asi: 'así', asignacion: 'asignación', atras: 'atrás', automatica: 'automática',
    automaticamente: 'automáticamente', automatico: 'automático', autorizacion: 'autorización', averia: 'avería',
    averias: 'averías', basica: 'básica', basico: 'básico', bogota: 'bogotá', boton: 'botón', buscala: 'búscala',
    buscalo: 'búscalo', busqueda: 'búsqueda', busquedas: 'búsquedas', calculo: 'cálculo', cancelacion: 'cancelación',
    catalogo: 'catálogo', catalogos: 'catálogos', categoria: 'categoría', categorias: 'categorías', cedula: 'cédula',
    ciudadania: 'ciudadanía', clasificacion: 'clasificación', codigo: 'código', codigos: 'códigos',
    comision: 'comisión', compania: 'compañía', condicion: 'condición', conexion: 'conexión',
    configuracion: 'configuración', confirmacion: 'confirmación', contrasena: 'contraseña', contrasenas: 'contraseñas',
    costarian: 'costarían', cotizacion: 'cotización', creacion: 'creación', credito: 'crédito', creditos: 'créditos',
    cuadricula: 'cuadrícula', cucuta: 'cúcuta', datafono: 'datáfono', datafonos: 'datáfonos', deberia: 'debería',
    debera: 'deberá', decision: 'decisión', dejala: 'déjala', dejalo: 'déjalo', demas: 'demás', deposito: 'depósito',
    descripcion: 'descripción', despues: 'después', detras: 'detrás', devolucion: 'devolución', dia: 'día', dias: 'días',
    dificil: 'difícil', digito: 'dígito', digitos: 'dígitos', direccion: 'dirección', diseno: 'diseño',
    disenos: 'diseños', dueno: 'dueño', duenos: 'dueños', economico: 'económico', edicion: 'edición',
    electronica: 'electrónica', electronico: 'electrónico', eliminacion: 'eliminación', eligela: 'elígela',
    eligelo: 'elígelo', envio: 'envío', espana: 'españa', envios: 'envíos', espanol: 'español', estan: 'están', estara: 'estará',
    estaria: 'estaría', exito: 'éxito', exportacion: 'exportación', extranjeria: 'extranjería', facil: 'fácil',
    facturacion: 'facturación', fisica: 'física', fisico: 'físico', fotografia: 'fotografía', garantia: 'garantía',
    grafica: 'gráfica', graficas: 'gráficas', guia: 'guía', guias: 'guías', habia: 'había', habra: 'habrá',
    ibague: 'ibagué', identificacion: 'identificación', imagenes: 'imágenes', importacion: 'importación',
    informacion: 'información', interes: 'interés', invalido: 'inválido', itagui: 'itagüí', jamas: 'jamás',
    linea: 'línea', lineas: 'líneas', llego: 'llegó', logica: 'lógica', manana: 'mañana', mas: 'más', maxima: 'máxima',
    maximo: 'máximo', mayusculas: 'mayúsculas', medellin: 'medellín', membresia: 'membresía', membresias: 'membresías',
    menu: 'menú', mercancia: 'mercancía', mexico: 'méxico', miercoles: 'miércoles', metodo: 'método', metodos: 'métodos', minima: 'mínima', minimas: 'mínimas',
    minimo: 'mínimo', minimos: 'mínimos', minusculas: 'minúsculas', monteria: 'montería', ningun: 'ningún',
    notificacion: 'notificación', numero: 'número', numeros: 'números', opcion: 'opción', operacion: 'operación',
    ordenes: 'órdenes', organizacion: 'organización', pagina: 'página', paginas: 'páginas', pais: 'país',
    paises: 'países', panama: 'panamá', peru: 'perú', pequena: 'pequeña', pequenas: 'pequeñas', pequeno: 'pequeño', pequenos: 'pequeños',
    pestana: 'pestaña', pestanas: 'pestañas', pidele: 'pídele', pidio: 'pidió', podra: 'podrá', podria: 'podría',
    popayan: 'popayán', posicion: 'posición', presentacion: 'presentación', promocion: 'promoción',
    proxima: 'próxima', proximas: 'próximas', proximo: 'próximo', proximos: 'próximos', quedaria: 'quedaría',
    quedo: 'quedó', quibdo: 'quibdó', quitarsela: 'quitársela', quizas: 'quizás', rapida: 'rápida', rapido: 'rápido',
    razon: 'razón', sabado: 'sábado', sabados: 'sábados', recepcion: 'recepción', region: 'región', renglon: 'renglón', rompio: 'rompió', sangria: 'sangría',
    sangrias: 'sangrías', seccion: 'sección', segun: 'según', seleccion: 'selección', senal: 'señal', sera: 'será',
    sesion: 'sesión', sincronizacion: 'sincronización', subarticulo: 'subartículo', subarticulos: 'subartículos',
    subcategoria: 'subcategoría', subcategorias: 'subcategorías', tambien: 'también', tamano: 'tamaño',
    tamanos: 'tamaños', tecnica: 'técnica', tecnico: 'técnico', telefono: 'teléfono', telefonos: 'teléfonos',
    tendra: 'tendrá', tendria: 'tendría', termino: 'término', tipografia: 'tipografía', titulo: 'título',
    titulos: 'títulos', todavia: 'todavía', transaccion: 'transacción', traves: 'través', ubicacion: 'ubicación',
    ultima: 'última', ultimas: 'últimas', ultimo: 'último', ultimos: 'últimos', unica: 'única', unico: 'único',
    vacia: 'vacía', vacio: 'vacío', valido: 'válido', verificacion: 'verificación', version: 'versión',
    vinculacion: 'vinculación',
};

/** Correctas en algunos usos («se publica», «yo autorizo»): se avisan con la sugerencia, no se corrigen. */
const AMBIGUOUS = {
    aplicara: 'aplicará', autorizo: 'autorizó', cobrara: 'cobrará', compro: 'compró', dejara: 'dejará', dejo: 'dejó',
    limite: 'límite', olvide: 'olvidé', perdida: 'pérdida', publica: 'pública', publico: 'público',
    quedara: 'quedará', seria: 'sería',
};

/** Frases sin ambigüedad aunque alguna de sus palabras sueltas la tenga. */
const PHRASES = [
    [/(?<!\p{L})olvide mi(?!\p{L})/giu, 'olvidé mi'],
    [/(?<!\p{L})al publico(?!\p{L})/giu, 'al público'],
    [/(?<!\p{L})o perdida(?!\p{L})/giu, 'o pérdida'],
    [/(?<!\p{L})se aplicara a(?!\p{L})/giu, 'se aplicará a'],
    [/(?<!\p{L})se cobro(?!\p{L})/giu, 'se cobró'],
    [/(?<!\p{L})aun no(?!\p{L})/giu, 'aún no'],
];

/** Cadenas enteras que son código aunque parezcan texto. */
const ALLOW = new Set([
    'miercoles', 'sabado', // Employees/Form: claves de work_schedule (la etiqueta sale de DAY_LABELS)
    'ALMACEN', // Stores/Warehouses: ejemplo de código de bodega; los códigos van sin tilde, como DEPOSITO
]);

// --- ¿Es un texto que ve alguien? ---------------------------------------------------------------

const CODE_ATTRS = new Set([
    'className', 'class', 'key', 'id', 'htmlFor', 'type', 'name', 'href', 'src', 'srcSet', 'method', 'as', 'role',
    'autoComplete', 'inputMode', 'enterKeyHint', 'target', 'rel', 'form', 'value', 'defaultValue', 'mode', 'variant',
    'size', 'tone', 'dataKey', 'fill', 'stroke', 'layout', 'orientation', 'weight', 'position', 'side', 'align', 'd',
    'viewBox', 'xmlns', 'loading', 'decoding', 'fetchPriority', 'crossOrigin', 'referrerPolicy', 'sandbox', 'allow',
    'accept', 'pattern', 'dir', 'lang', 'step', 'min', 'max', 'routeName', 'icon', 'permission', 'scheme',
]);
const UI_ARIA = new Set(['aria-label', 'aria-description', 'aria-roledescription', 'aria-valuetext', 'aria-placeholder']);
const CODE_PROPS = new Set([
    'key', 'slug', 'value', 'id', 'type', 'kind', 'status', 'mode', 'variant', 'tone', 'icon', 'href', 'url', 'path',
    'route', 'routeName', 'method', 'permission', 'feature', 'match', 'field', 'column', 'scheme', 'layout', 'size',
    'position', 'align', 'target', 'rel', 'as', 'color', 'pestana', 'tab', 'page', 'group', 'sort', 'direction',
    'event', 'channel', 'className', 'dataKey', 'storageKey',
]);
const UI_PROPS = new Set([
    'label', 'title', 'description', 'hint', 'placeholder', 'message', 'text', 'caption', 'help', 'empty',
    'emptyTitle', 'emptyDescription', 'confirmLabel', 'cancelLabel', 'subtitle', 'tooltip', 'error', 'success',
    'summary', 'heading', 'note', 'prompt', 'question', 'answer', 'cta', 'ctaLabel', 'badge', 'alt', 'legend', 'body',
    'short', 'long', 'singular', 'plural', 'action', 'eyebrow', 'detail', 'reason', 'warning', 'info',
]);
const CODE_CALLS = new Set([
    'route', 'cn', 'clsx', 'twMerge', 'cva', 'get', 'post', 'put', 'patch', 'delete', 'visit', 'reload', 'getItem',
    'setItem', 'removeItem', 'open', 'fetch', 'querySelector', 'querySelectorAll', 'addEventListener',
    'removeEventListener', 'matchMedia', 'getAttribute', 'setAttribute', 'removeAttribute', 'has', 'current',
    'startsWith', 'endsWith', 'includes', 'split', 'join', 'replace', 'replaceAll', 'test', 'match', 'set', 'append',
    'useTableView', 'createElement', 'getElementById', 'URL', 'URLSearchParams', 'parseISO', 'require', 'useRemember',
    'useForm', 'setData', 'listen', 'private', 'channel', 'leave', 'on', 'off', 'emit', 'indexOf', 'lastIndexOf',
    'localeCompare', 'reset', 'clearErrors', 'setError', 'transform', 'log', 'warn', 'debug', 'update', 'setFilter',
]);
const COMPARISONS = new Set([
    ts.SyntaxKind.EqualsEqualsEqualsToken,
    ts.SyntaxKind.ExclamationEqualsEqualsToken,
    ts.SyntaxKind.EqualsEqualsToken,
    ts.SyntaxKind.ExclamationEqualsToken,
    ts.SyntaxKind.InKeyword,
]);

const calleeName = (expr) => (ts.isIdentifier(expr) ? expr.text : ts.isPropertyAccessExpression(expr) ? expr.name.text : '');
const isToast = (call) =>
    calleeName(call.expression) === 'toast' ||
    (ts.isPropertyAccessExpression(call.expression) && calleeName(call.expression.expression) === 'toast');

/** `ui`: se ve en pantalla y se corrige solo. `review`: puede ser texto o código. `skip`: es código. */
function classify(node) {
    if (ts.isJsxText(node)) {
        return 'ui';
    }

    // Sin espacios y con signos de código: «tienda-en-linea», «storefront.editor.look», «/tienda».
    if (!/\s/.test(node.text) && /[-_./:#?=&@[\]]/.test(node.text)) {
        return 'skip';
    }

    let child = node;
    let parent = node.parent;

    while (parent) {
        if (ts.isImportDeclaration(parent) || ts.isExportDeclaration(parent) || ts.isExternalModuleReference(parent)) return 'skip';
        if (ts.isLiteralTypeNode(parent) || ts.isTypeNode(parent)) return 'skip';
        if (ts.isElementAccessExpression(parent) && parent.argumentExpression === child) return 'skip';
        if (ts.isCaseClause(parent) && parent.expression === child) return 'skip';
        if (ts.isBinaryExpression(parent) && COMPARISONS.has(parent.operatorToken.kind)) return 'skip';

        if (ts.isPropertyAssignment(parent) || ts.isPropertySignature(parent) || ts.isPropertyDeclaration(parent)) {
            if (parent.name === child) return 'skip';
            const name = parent.name.getText().replace(/['"`]/g, '');
            if (CODE_PROPS.has(name)) return 'skip';
            if (UI_PROPS.has(name)) return 'ui';
        }

        if (ts.isCallExpression(parent) || ts.isNewExpression(parent)) {
            if (parent.expression === child) return 'skip';
            if (ts.isCallExpression(parent) && isToast(parent)) return 'ui';
            if (CODE_CALLS.has(calleeName(parent.expression))) return 'skip';
            return 'review';
        }

        if (ts.isJsxAttribute(parent)) {
            const name = parent.name.getText();
            if (UI_ARIA.has(name)) return 'ui';
            if (CODE_ATTRS.has(name) || name.startsWith('data-') || name.startsWith('aria-')) return 'skip';
            return 'ui';
        }

        if (ts.isJsxExpression(parent) && (ts.isJsxElement(parent.parent) || ts.isJsxFragment(parent.parent))) return 'ui';

        if (ts.isVariableDeclaration(parent) || ts.isReturnStatement(parent) || ts.isBlock(parent) || ts.isSourceFile(parent) || ts.isFunctionLike(parent)) {
            return 'review';
        }

        child = parent;
        parent = parent.parent;
    }

    return 'review';
}

// --- «esta» y «aun»: verbo o demostrativo según la palabra vecina -----------------------------

const VERB_NEXT = new Set([
    'en', 'a', 'al', 'bajo', 'de', 'del', 'con', 'sin', 'por', 'para', 'aqui', 'aquí', 'alli', 'allí', 'ahi', 'ahí',
    'arriba', 'abajo', 'bien', 'mal', 'afuera', 'fuera', 'dentro', 'cerca', 'lejos', 'hoy', 'ya', 'todavia', 'todavía',
    'aun', 'aún', 'tan', 'muy', 'mas', 'más', 'menos', 'siendo', 'pendiente', 'disponible', 'vigente', 'visible',
    'activa', 'inactiva', 'abierta', 'cerrada', 'vacia', 'vacía', 'llena', 'completa', 'incompleta', 'apagada',
    'encendida', 'oculta', 'lista', 'libre', 'este', 'esta', 'esto', 'ese', 'esa', 'eso',
]);
const VERB_PREV = new Set(['no', 'ya', 'donde', 'dónde', 'aun', 'aún', 'todavia', 'todavía', 'nadie', 'nada', 'todo', 'ello', 'quien', 'quién', 'eso', 'esto']);
const FEMININE_IN_O = new Set(['foto', 'fotos', 'mano', 'manos', 'moto', 'motos', 'radio']);
const WORDS_IN_O = new Set(['como', 'cuando', 'pero', 'todo', 'todos', 'solo', 'tampoco', 'poco', 'algo', 'mismo', 'otro', 'otros', 'ninguno', 'nosotros']);
const NOUNS_IN_ADA = new Set(['entrada', 'salida', 'llegada', 'jornada', 'temporada', 'llamada', 'partida', 'medida', 'bebida', 'comida', 'vida', 'mirada', 'parada', 'portada']);
const AUN_ACCENT_NEXT = new Set(['no', 'hay', 'falta', 'faltan', 'queda', 'quedan', 'sin', 'es', 'son', 'esta', 'está', 'estan', 'están', 'tiene', 'tienen', 'puedes', 'puede', 'se', 'lo', 'la', 'le']);

/** «Este cliente esta al día» es verbo; «esta sede» es demostrativo y no lleva tilde. */
function estaIsVerb(prev, next) {
    if (next) {
        const word = next.toLowerCase();

        return (
            VERB_NEXT.has(word) ||
            /(ando|iendo|yendo)$/.test(word) ||
            (word.length >= 4 && /os?$/.test(word) && !FEMININE_IN_O.has(word) && !WORDS_IN_O.has(word)) ||
            (/[ai]das?$/.test(word) && !NOUNS_IN_ADA.has(word))
        );
    }

    return Boolean(prev && VERB_PREV.has(prev.toLowerCase()));
}

const keepCase = (original, fixed) => {
    if (original.length > 1 && original === original.toUpperCase()) return fixed.toUpperCase();
    if (original[0] === original[0].toUpperCase()) return fixed[0].toUpperCase() + fixed.slice(1);

    return fixed;
};

/** Revisa un texto y devuelve lo que encontró, con su posición dentro de ese texto. */
function inspectText(raw) {
    const found = [];
    const taken = new Set();

    for (const [pattern, replacement] of PHRASES) {
        for (const match of raw.matchAll(pattern)) {
            const words = [...match[0].matchAll(/\p{L}+/gu)].map((part) => match.index + part.index);

            if (words.some((offset) => taken.has(offset))) continue;

            words.forEach((offset) => taken.add(offset));
            found.push({ offset: match.index, word: match[0], suggestion: keepCase(match[0], replacement), kind: 'error' });
        }
    }

    const tokens = [...raw.matchAll(/\p{L}+/gu)].map((match) => ({ word: match[0], offset: match.index }));

    tokens.forEach((token, index) => {
        if (taken.has(token.offset) || /[áéíóúüñ]/i.test(token.word)) return;

        const lower = token.word.toLowerCase();
        // Solo cuenta como vecina la palabra separada por espacios, no por un punto o una coma.
        const before = tokens[index - 1];
        const after = tokens[index + 1];
        const prev = before && /^\s+$/.test(raw.slice(before.offset + before.word.length, token.offset)) ? before.word : null;
        const next = after && /^\s+$/.test(raw.slice(token.offset + token.word.length, after.offset)) ? after.word : null;
        const add = (suggestion, kind) => found.push({ offset: token.offset, word: token.word, suggestion: keepCase(token.word, suggestion), kind });

        if (SAFE[lower]) {
            add(SAFE[lower], 'error');
        } else if (lower === 'esta') {
            if (estaIsVerb(prev, next)) add('está', 'error');
        } else if (lower === 'aun') {
            add('aún', next && AUN_ACCENT_NEXT.has(next.toLowerCase()) ? 'error' : 'warning');
        } else if (AMBIGUOUS[lower]) {
            add(AMBIGUOUS[lower], 'warning');
        }
    });

    return found;
}

// --- Recorrido -------------------------------------------------------------------------------

function walk(dir, accept, out = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) walk(path, accept, out);
        else if (accept(path)) out.push(path);
    }

    return out;
}

const shown = (file) => file.split(sep).join('/');
const findings = [];
let fixedWords = 0;
const fixedFiles = new Set();

for (const file of walk(join('resources', 'js'), (path) => /\.tsx?$/.test(path) && !path.endsWith('.d.ts'))) {
    const source = readFileSync(file, 'utf8');
    const sf = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
    const lines = source.split('\n');
    const edits = [];

    const visit = (node) => {
        const isText =
            ts.isJsxText(node) ||
            ts.isStringLiteral(node) ||
            ts.isNoSubstitutionTemplateLiteral(node) ||
            ts.isTemplateHead(node) ||
            ts.isTemplateMiddle(node) ||
            ts.isTemplateTail(node);

        if (isText && !ALLOW.has(node.text.trim())) {
            const start = node.getStart(sf);
            const line = sf.getLineAndCharacterOfPosition(start).line;
            const kindOfText = classify(node);
            // Los códigos del proyecto van en inglés y sin espacios: una frase es texto de interfaz.
            const context = kindOfText === 'review' && /\p{L}\s+\p{L}/u.test(node.text) ? 'ui' : kindOfText;

            if (context !== 'skip' && !lines[line]?.includes('tildes-ok')) {
                const raw = source.slice(start, node.getEnd());

                for (const hit of inspectText(raw)) {
                    if (fix && hit.kind === 'error' && context === 'ui') {
                        edits.push([start + hit.offset, start + hit.offset + hit.word.length, hit.suggestion]);
                        continue;
                    }

                    findings.push({
                        ...hit,
                        file: shown(file),
                        line: sf.getLineAndCharacterOfPosition(start + hit.offset).line + 1,
                        manual: context !== 'ui',
                        text: raw.replace(/\s+/g, ' ').trim().slice(0, 70),
                    });
                }
            }
        }

        ts.forEachChild(node, visit);
    };

    visit(sf);

    if (edits.length > 0) {
        let output = source;

        for (const [from, to, text] of edits.sort((a, b) => b[0] - a[0])) {
            output = output.slice(0, from) + text + output.slice(to);
        }

        writeFileSync(file, output);
        fixedWords += edits.length;
        fixedFiles.add(file);
    }
}

// Blade: se borra lo que no es texto conservando las posiciones, y lo que queda se revisa.
const blank = (match) => match.replace(/[^\n]/g, ' ');

for (const file of walk(join('resources', 'views'), (path) => path.endsWith('.blade.php'))) {
    const source = readFileSync(file, 'utf8');
    const text = source
        .replace(/\{\{--[\s\S]*?--\}\}/g, blank)
        .replace(/@php[\s\S]*?@endphp/g, blank)
        .replace(/<\?php[\s\S]*?\?>/g, blank)
        .replace(/<(script|style)[\s\S]*?<\/\1>/gi, blank)
        .replace(/\{\{[\s\S]*?\}\}|\{!![\s\S]*?!!\}/g, blank)
        .replace(/@\w+(\s*\((?:[^()]|\([^()]*\))*\))?/g, blank)
        .replace(/<[^>]*>/g, blank);
    const lines = source.split('\n');

    for (const hit of inspectText(text)) {
        const line = source.slice(0, hit.offset).split('\n').length;

        if (lines[line - 1]?.includes('tildes-ok')) continue;

        findings.push({ ...hit, file: shown(file), line, manual: true, text: lines[line - 1].trim().slice(0, 70) });
    }
}

// --- Informe ---------------------------------------------------------------------------------

const errors = findings.filter((finding) => finding.kind === 'error');
const warnings = findings.filter((finding) => finding.kind === 'warning');

if (list) {
    for (const finding of [...errors, ...warnings]) {
        const tag = finding.kind === 'warning' ? 'dudosa  ' : finding.manual ? 'a mano  ' : 'corregir';

        console.log(`${tag}  ${finding.file}:${finding.line}  ${finding.word} → ${finding.suggestion}  «${finding.text}»`);
    }

    console.log('');
}

if (fix) {
    console.log(`Corregidas: ${fixedWords} palabras en ${fixedFiles.size} archivos.`);
}

const files = new Set(errors.map((finding) => finding.file)).size;
const manual = errors.filter((finding) => finding.manual).length;

console.log(`Por corregir: ${errors.length} en ${files} archivos (${manual} a mano). Dudosas: ${warnings.length}.`);

if (errors.length > 0) {
    process.exit(1);
}
