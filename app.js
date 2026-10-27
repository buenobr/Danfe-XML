const form = document.querySelector('#lookup-form');
const input = document.querySelector('#chave');
const counter = document.querySelector('#counter');
const errorBox = document.querySelector('#key-error');
const submitBtn = document.querySelector('#submit-btn');
const result = document.querySelector('#result');
const fileInput = document.querySelector('#xml-file');
const xmlStatus = document.querySelector('#xml-status');
let autoSubmitTimer = null;
const danfeFile = document.querySelector('#danfe-file');
const danfeStatus = document.querySelector('#danfe-status');
const danfeChoices = document.querySelector('#danfe-choices');
document.querySelector('#year').textContent = new Date().getFullYear();

function digits(value) { return value.replace(/\D/g, '').slice(0, 44); }
function validMod11(key) {
  let sum = 0, weight = 2;
  for (let i = 42; i >= 0; i--) {
    sum += Number(key[i]) * weight;
    weight = weight === 9 ? 2 : weight + 1;
  }
  const remainder = sum % 11;
  const expected = remainder === 0 || remainder === 1 ? 0 : 11 - remainder;
  return expected === Number(key[43]);
}
input.addEventListener('input', () => {
  const value = digits(input.value);
  input.value = value;
  counter.textContent = `${value.length} / 44`;
  errorBox.hidden = true;
});
function showError(message) {
  errorBox.textContent = message;
  errorBox.hidden = false;
  result.hidden = true;
}
function showResult(key, files, data = {}) {
  result.hidden = false;
  const isNfce = key.slice(20, 22) === '65';
  if (isNfce) {
    const official = data.consulta_oficial;
    // O botão envia a chave via POST para o formulário oficial; não tenta editar
    // um campo em outro domínio (bloqueado pela política de mesma origem).
    const officialLink = official
      ? `<button type="button" id="sefaz-direct-post">Consultar na SEFAZ-RS com chave preenchida ↗</button>` : '';
    result.innerHTML = `<h2>NFC-e consultada na SEFAZ-RS</h2>
      <p>Os dados foram encontrados na consulta pública da SEFAZ-RS. Abra o DANFE e use a opção de impressão do navegador para salvar em PDF.</p>
      <div class="result-actions"><a href="/api.php?action=html&amp;chave=${key}" target="_blank" rel="noopener">Visualizar / Salvar DANFE NFC-e em PDF</a>${officialLink}</div>`;
  } else {
    const xmlAction = files?.xml
      ? `<a class="secondary" href="/api.php?action=xml&amp;chave=${key}">Baixar XML no padrão SEFAZ (sem assinatura)</a>`
      : `<p><strong>XML indisponível:</strong> não foi possível montar a cópia estruturada a partir da resposta do provedor.</p>`;
    const pdfAction = files?.pdf
      ? `<a href="/api.php?action=pdf&amp;chave=${key}">Baixar DANFE em PDF</a>` : '';
    result.innerHTML = `<h2>Consulta concluída</h2><p>A consulta ao provedor foi concluída.</p><div class="result-actions">${pdfAction}<a class="secondary" href="/api.php?action=html&amp;chave=${key}" target="_blank" rel="noopener">Abrir visualização HTML</a>${xmlAction}</div>`;
  }
  const sefazButton = result.querySelector('#sefaz-direct-post');
  if (sefazButton) sefazButton.addEventListener('click', () => {
    // O endpoint da SEFAZ processa a chave recebida; ele normalmente abre o
    // resultado da consulta diretamente, não o formulário editável preenchido.
    const directForm = document.createElement('form');
    directForm.method = 'POST';
    directForm.action = 'https://www.sefaz.rs.gov.br/ASP/AAE_ROOT/NFE/SAT-WEB-NFE-NFC_2.asp';
    directForm.target = '_blank';
    directForm.style.display = 'none';
    for (const [name, value] of Object.entries({ chaveNFe: key, HML: 'false' })) {
      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = name;
      field.value = value;
      directForm.appendChild(field);
    }
    document.body.appendChild(directForm);
    directForm.submit();
    directForm.remove();
  });
  result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function scheduleLookup() {
  if (autoSubmitTimer) clearTimeout(autoSubmitTimer);
  autoSubmitTimer = setTimeout(() => form.requestSubmit(), 250);
}
form.addEventListener('submit', async event => {
  event.preventDefault();
  const key = digits(input.value);
  if (key.length !== 44) return showError('Informe os 44 dígitos da chave de acesso.');
  if (!validMod11(key)) return showError('O dígito verificador não confere. Verifique se a chave foi digitada corretamente.');
  submitBtn.disabled = true;
  submitBtn.querySelector('span:first-child').textContent = 'Consultando provedor fiscal…';
  errorBox.hidden = true;
  result.hidden = true;
  try {
    const response = await fetch('/api.php?action=consulta', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chave: key })
    });
    const data = await response.json();
    if (!response.ok) {
      if (data.code === 'XML_REQUIRED') {
        showError('Esta nota não está disponível pela consulta de chave. Importe o XML autorizado completo usando a opção abaixo.');
        xmlStatus.hidden = false;
        xmlStatus.textContent = 'Para gerar notas antigas, selecione o XML autorizado (nfeProc).';
        return;
      }
      throw new Error(data.error || 'Não foi possível consultar a nota.');
    }
    showResult(key, data.arquivos, data);
  } catch (err) {
    showError(err.message || 'Falha de comunicação. Tente novamente.');
  } finally {
    submitBtn.disabled = false;
    submitBtn.querySelector('span:first-child').textContent = 'Consultar nota';
  }
});
fileInput.addEventListener('change', async () => {
  const file = fileInput.files?.[0];
  if (!file) return;
  xmlStatus.hidden = false;
  xmlStatus.textContent = 'Lendo XML e preparando o DANFE…';
  try {
    if (file.size < 1 || file.size > 5 * 1024 * 1024) throw new Error('O arquivo deve ter até 5 MB.');
    const xml = await file.text();
    if (/<!DOCTYPE|<!ENTITY/i.test(xml)) throw new Error('O XML contém declarações proibidas.');
    const doc = new DOMParser().parseFromString(xml, 'application/xml');
    if (doc.querySelector('parsererror')) throw new Error('O arquivo selecionado não é um XML válido.');
    const root = doc.documentElement;
    const info = doc.getElementsByTagNameNS('*', 'infNFe').item(0);
    if (!root || root.localName !== 'nfeProc' || !info) throw new Error('Envie o XML autorizado completo da NF-e (nfeProc).');
    const rawId = info.getAttribute('Id') || '';
    const key = rawId.startsWith('NFe') ? rawId.slice(3) : '';
    if (!/^\d{44}$/.test(key) || key.slice(20, 22) !== '55' || !validMod11(key)) throw new Error('A chave no XML é inválida ou não corresponde ao modelo NF-e 55.');
    const protocolKey = doc.getElementsByTagNameNS('*', 'chNFe').item(0)?.textContent?.trim();
    const status = doc.getElementsByTagNameNS('*', 'cStat').item(0)?.textContent?.trim();
    if (protocolKey !== key || status !== '100') throw new Error('O XML não possui protocolo de autorização válido para esta chave.');
    input.value = key;
    counter.textContent = '44 / 44';
    submitBtn.disabled = true;
    xmlStatus.textContent = 'XML autorizado validado. Gerando o DANFE diretamente deste arquivo…';
    const data = new FormData();
    data.append('chave', key);
    data.append('xml', file, file.name);
    const response = await fetch('/api.php?action=xml-danfe', { method: 'POST', body: data });
    if (!response.ok) {
      const body = await response.json().catch(() => ({}));
      throw new Error(body.error || 'Não foi possível gerar o DANFE deste XML.');
    }
    const html = await response.text();
    const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
    result.hidden = false;
    result.innerHTML = `<h2>DANFE gerado a partir do XML</h2><p>O XML autorizado foi processado sem consulta à API.</p><div class="result-actions"><a href="${url}" target="_blank" rel="noopener">Visualizar / Imprimir DANFE</a></div>`;
    xmlStatus.textContent = 'DANFE pronto. Use o botão abaixo para visualizar ou imprimir.';
  } catch (err) {
    xmlStatus.textContent = err.message || 'Não foi possível processar o arquivo XML.';
  } finally {
    submitBtn.disabled = false;
    fileInput.value = '';
  }
});

// DANFE import: barcode detection where supported, PDF text extraction, then OCR.
// Images and PDFs stay in the browser; only OCR libraries are loaded from a CDN.
const OCR_TIMEOUT_MS = 90000;
function updateDanfeStatus(message) {
  danfeStatus.hidden = false;
  danfeStatus.textContent = message;
}
function findAccessKeys(text) {
  const candidates = new Set();
  const raw = String(text || '');
  // Exact 44 consecutive digits, even when embedded in OCR punctuation.
  for (const match of raw.matchAll(/(?<!\d)\d{44}(?!\d)/g)) candidates.add(match[0]);
  // Typical DANFE access key printed in groups of 4 digits separated by spaces.
  for (const match of raw.matchAll(/(?<!\d)(?:\d{4}[\s.\-]*){10}\d{4}(?!\d)/g)) {
    candidates.add(match[0].replace(/\D/g, ''));
  }
  // OCR often introduces newlines / whitespace between digit groups.
  for (const line of raw.split(/\n/)) {
    const stripped = line.replace(/[^\d]/g, '');
    if (stripped.length === 44) candidates.add(stripped);
  }
  return [...candidates].filter(k => k.length === 44 && validMod11(k));
}
function applyAccessKey(key) {
  input.value = key;
  counter.textContent = '44 / 44';
  errorBox.hidden = true;
  result.hidden = true;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}
function presentAccessKeys(keys) {
  danfeChoices.replaceChildren();
  if (!keys.length) return false;
  if (keys.length === 1) {
    applyAccessKey(keys[0]);
    updateDanfeStatus('Chave identificada. Consultando automaticamente…');
    scheduleLookup();
    return true;
  }
  updateDanfeStatus('Encontramos mais de uma chave válida. Selecione a chave da nota que deseja consultar:');
  danfeChoices.hidden = false;
  for (const key of keys) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = key.replace(/(.{4})/g, '$1 ').trim();
    button.addEventListener('click', () => {
      applyAccessKey(key);
      updateDanfeStatus('Chave selecionada. Consultando automaticamente…');
      scheduleLookup();
      danfeChoices.hidden = true;
    });
    danfeChoices.append(button);
  }
  return true;
}
const scriptCache = new Map();
function loadExternalScript(url, globalName) {
  if (window[globalName]) return Promise.resolve(window[globalName]);
  if (!scriptCache.has(url)) {
    scriptCache.set(url, new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = url;
      script.crossOrigin = 'anonymous';
      script.onload = () => window[globalName] ? resolve(window[globalName]) : reject(new Error('Biblioteca de leitura indisponível.'));
      script.onerror = () => reject(new Error('Não foi possível carregar o leitor OCR. Verifique se o navegador ou alguma extensão está bloqueando o CDN.'));
      document.head.appendChild(script);
    }));
  }
  return scriptCache.get(url);
}
function canvasFromImage(image, scale = 1) {
  const maxSide = 2600;
  const factor = Math.min(scale, maxSide / Math.max(image.width, image.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(image.width * factor));
  canvas.height = Math.max(1, Math.round(image.height * factor));
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
  return canvas;
}
function highContrast(canvas) {
  const copy = document.createElement('canvas');
  copy.width = canvas.width;
  copy.height = canvas.height;
  const ctx = copy.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(canvas, 0, 0);
  const pixels = ctx.getImageData(0, 0, copy.width, copy.height);
  for (let i = 0; i < pixels.data.length; i += 4) {
    const gray = 0.299 * pixels.data[i] + 0.587 * pixels.data[i + 1] + 0.114 * pixels.data[i + 2];
    const value = gray > 165 ? 255 : gray < 85 ? 0 : Math.round((gray - 85) * 255 / 80);
    pixels.data[i] = pixels.data[i + 1] = pixels.data[i + 2] = value;
  }
  ctx.putImageData(pixels, 0, 0);
  return copy;
}
async function barcodeKeys(canvas) {
  if (!('BarcodeDetector' in window)) return [];
  try {
    const formats = await BarcodeDetector.getSupportedFormats();
    const supported = ['code_128', 'code_39', 'itf', 'qr_code'].filter(x => formats.includes(x));
    if (!supported.length) return [];
    const detector = new BarcodeDetector({ formats: supported });
    const codes = await detector.detect(canvas);
    return codes.flatMap(x => findAccessKeys(x.rawValue));
  } catch { return []; }
}
async function ocrKeys(canvases) {
  const Tesseract = await loadExternalScript(
    'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js', 'Tesseract'
  );
  const worker = await Tesseract.createWorker('eng', 1, {
    logger: msg => {
      if (msg.status === 'recognizing text') updateDanfeStatus(`Lendo caracteres do DANFE… ${Math.round((msg.progress || 0) * 100)}%`);
    }
  });
  try {
    await worker.setParameters({ tessedit_char_whitelist: '0123456789 ', preserve_interword_spaces: '1' });
    for (const canvas of canvases) {
      for (const variant of [canvas, highContrast(canvas)]) {
        const result = await worker.recognize(variant);
        const keys = findAccessKeys(result.data.text);
        if (keys.length) return keys;
      }
    }
    return [];
  } finally {
    await worker.terminate();
  }
}
async function imageToCanvas(file) {
  const bitmap = await createImageBitmap(file);
  try { return canvasFromImage(bitmap, Math.max(1, 1600 / Math.max(bitmap.width, bitmap.height))); }
  finally { bitmap.close(); }
}
async function extractPdf(file) {
  const pdfjs = await loadExternalScript(
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', 'pdfjsLib'
  );
  pdfjs.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  const bytes = new Uint8Array(await file.arrayBuffer());
  const pdf = await pdfjs.getDocument({ data: bytes }).promise;
  const pages = Math.min(pdf.numPages, 3);
  const canvases = [];
  const textParts = [];
  for (let i = 1; i <= pages; i++) {
    updateDanfeStatus(`Lendo PDF — página ${i} de ${pages}…`);
    const page = await pdf.getPage(i);
    const text = await page.getTextContent();
    textParts.push(text.items.map(x => x.str).join(' '));
    const viewport = page.getViewport({ scale: Math.min(2.4, 2200 / page.getViewport({ scale: 1 }).width) });
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(viewport.width);
    canvas.height = Math.round(viewport.height);
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    canvases.push(canvas);
  }
  await pdf.destroy();
  return { keys: findAccessKeys(textParts.join('\n')), canvases };
}
danfeFile.addEventListener('change', async () => {
  const file = danfeFile.files?.[0];
  if (!file) return;
  danfeChoices.hidden = true;
  danfeChoices.replaceChildren();
  updateDanfeStatus('Preparando leitura do DANFE…');
  try {
    if (file.size > 15 * 1024 * 1024) throw new Error('Selecione um arquivo de até 15 MB.');
    const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
    const isImage = /^image\/(jpeg|png|webp)$/.test(file.type) || /\.(jpe?g|png|webp)$/i.test(file.name);
    if (!isPdf && !isImage) throw new Error('Formato não suportado. Use PDF, JPG, PNG ou WEBP.');
    let keys = [], canvases = [];
    if (isPdf) {
      const pdfData = await extractPdf(file);
      keys = pdfData.keys;
      canvases = pdfData.canvases;
    } else {
      canvases = [await imageToCanvas(file)];
    }
    if (!keys.length) {
      for (const canvas of canvases) {
        keys = await barcodeKeys(canvas);
        if (keys.length) break;
      }
    }
    if (!keys.length) {
      updateDanfeStatus('Executando OCR. A primeira leitura pode demorar alguns segundos…');
      keys = await Promise.race([
        ocrKeys(canvases),
        new Promise((_, reject) => setTimeout(() => reject(new Error('O reconhecimento demorou demais. Tente uma imagem mais nítida.')), OCR_TIMEOUT_MS))
      ]);
    }
    if (!presentAccessKeys(keys)) {
      throw new Error('Não foi possível identificar uma chave válida de 44 dígitos. Tente uma foto mais nítida, sem cortes, com a chave e o código de barras visíveis. Você também pode digitá-la manualmente.');
    }
  } catch (error) {
    updateDanfeStatus(error.message || 'Falha ao analisar o DANFE.');
  } finally {
    danfeFile.value = '';
  }
});

