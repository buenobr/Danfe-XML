# Danfe-XML

Consulta e gera representações de documentos fiscais eletrônicos em uma interface web leve, preparada para hospedagem compartilhada com PHP.

## Recursos

- Consulta de NF-e pela chave de acesso usando a API ConsultaDANFE.
- Importação de XML autorizado (NF-e modelo 55) para gerar a visualização DANFE diretamente do arquivo.
- Leitura de chaves em imagens e PDFs por código de barras e OCR, com consulta automática quando uma única chave válida é identificada.
- Consulta de NFC-e do Rio Grande do Sul na SEFAZ-RS e visualização para impressão.

## Instalação

1. Envie os arquivos deste repositório para a pasta pública do site, como `htdocs`.
2. Mantenha juntos `api.php`, `index.html`, `app.js`, `styles.css`, os arquivos de impressão e os arquivos de marca.
3. Use PHP 8 ou superior com as extensões cURL, DOM, libxml e JSON habilitadas.
4. Configure a hospedagem para aceitar uploads de até 5 MB e acesse o site por HTTPS.
5. Consulte `/api.php?action=health` para verificar a versão do PHP e as extensões principais.

## Notas de operação

A consulta por chave depende da disponibilidade e das regras da API ConsultaDANFE. Para notas antigas recusadas pelo serviço, é necessário possuir o XML autorizado completo (`nfeProc`). O arquivo é processado pelo próprio site e não é encaminhado à API fiscal.

O OCR é executado no navegador e carrega bibliotecas externas por CDN, por isso requer conexão à internet. A visualização pode ser impressa ou salva como PDF, mas não substitui o XML fiscal nem um documento oficial assinado.

A consulta de NFC-e está integrada à SEFAZ-RS. Sua disponibilidade e o conteúdo retornado dependem do serviço oficial.

## Arquivos principais

- `index.html`, `styles.css`, `app.js`: interface e leitura de documentos no navegador.
- `api.php`: validação, consulta de NF-e, processamento de XML e integração com a SEFAZ-RS.
- `nfce-print.css`, `nfce-print.js`: visualização e impressão da NFC-e.
- `xml-order.json`: ordem dos elementos do XML normalizado.

