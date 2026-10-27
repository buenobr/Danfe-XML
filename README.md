# Danfe-XML

Consulta e gera representações de documentos fiscais eletrônicos em uma interface web leve, preparada para hospedagem compartilhada com PHP.

## Recursos

- Consulta de NF-e pela chave de acesso usando a API ConsultaDANFE.
- Importação de XML autorizado (NF-e modelo 55) para gerar a visualização DANFE diretamente do arquivo.
- Leitura de chaves em imagens e PDFs por código de barras e OCR, com consulta automática quando uma única chave válida é identificada.
- Consulta de NFC-e do Rio Grande do Sul na SEFAZ-RS e visualização para impressão.

## Instalação

1. Envie os arquivos deste repositório para a pasta pública do site (por exemplo, `htdocs`).
2. Mantenha `api.php`, `index.html`, `app.js`, `styles.css`, `nfce-print.css`, `nfce-print.js`, os arquivos de marca e `xml-order.json` juntos.
3. Habilite no PHP as extensões cURL, DOM, libxml e JSON. A hospedagem também precisa permitir upload de arquivos de até 5 MB.
4. Acesse o domínio e teste a consulta. O endpoint `api.php?action=health` informa se os principais recursos do PHP estão disponíveis.

## Requisitos e observações

- Hospedagem com PHP 8 ou superior, HTTPS, cURL, DOM e suporte a uploads multipart.
- A consulta por chave depende da disponibilidade e das regras da API ConsultaDANFE.
- Para notas antigas recusadas pela API, é necessário possuir o XML autorizado completo (`nfeProc`). O XML não é enviado à API fiscal; é processado pelo próprio site.
- O OCR é executado no navegador e carrega bibliotecas externas por CDN, portanto requer conexão à internet.
- A visualização impressa é uma representação para consulta. Ela não substitui o XML fiscal nem um documento oficial assinado.
- A consulta de NFC-e está integrada à SEFAZ-RS; disponibilidade e conteúdo podem mudar conforme o serviço oficial.

## Estrutura

- `index.html`, `styles.css`, `app.js`: interface e leitura de documentos no navegador.
- `api.php`: validação, consulta de NF-e, processamento seguro de XML e integração da NFC-e RS.
- `nfce-print.css`, `nfce-print.js`: visualização e impressão da NFC-e.
- `xml-order.json`: ordem dos elementos do XML normalizado.
