INSTALAÇÃO

1. Faça uma cópia de segurança dos arquivos atuais do site.
2. Envie todos os arquivos deste pacote para a pasta pública da hospedagem (por exemplo, htdocs), mantendo-os juntos.
3. Confirme que o servidor executa PHP 8 ou superior e habilite as extensões cURL, DOM, libxml e JSON.
4. Permita uploads de até 5 MB. O valor de post_max_size deve ser maior que upload_max_filesize.
5. Acesse o site por HTTPS, atualize o navegador e teste uma chave de NF-e e uma NFC-e do Rio Grande do Sul.

CONSULTA E XML

A consulta por chave usa o serviço ConsultaDANFE. Se a API não aceitar a chave, importe o XML autorizado completo da NF-e (nfeProc). O sistema verifica a chave e o protocolo de autorização e gera uma visualização HTML diretamente dos dados do XML. Essa operação não envia o arquivo à API fiscal.

A representação pode ser impressa ou salva em PDF pelo navegador. Ela não substitui o XML fiscal original nem constitui um PDF oficial assinado. O fluxo de importação aceita NF-e modelo 55; XML incompleto, arquivo de evento e NFC-e não são aceitos para essa geração.

A leitura de imagens e PDFs ocorre no navegador. O OCR carrega bibliotecas externas por CDN e depende de conexão à internet. Quando encontra uma única chave válida, inicia a consulta automaticamente; se encontrar várias, solicita a escolha da nota.

NFC-e RS

A consulta integrada utiliza a SEFAZ-RS. O conteúdo e a disponibilidade dependem do serviço oficial. Na página de impressão, use “Imprimir / Salvar em PDF” e selecione “Salvar como PDF”.

DIAGNÓSTICO

O endereço /api.php?action=health informa a versão do PHP e a disponibilidade das extensões cURL e DOM. Se o upload falhar, confira também os limites de tamanho configurados pela hospedagem.

