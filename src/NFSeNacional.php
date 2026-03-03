<?php

namespace Divulgueregional\ApiNfse;

use DOMDocument;
use DOMXPath;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Classe para comunicação com a API do Sistema Nacional NFS-e
 * Baseada na documentação técnica do SEFIN Nacional
 */
class NFSeNacional
{
    // URLs de Produção
    private const URL_PRODUCAO = 'https://sefin.nfse.gov.br/SefinNacional';
    private const URL_PRODUCAO_DANFSE = 'https://adn.nfse.gov.br/danfse';
    private const URL_PRODUCAO_CONSULTA_PUBLICA = 'https://www.nfse.gov.br/ConsultaPublica';
    private const URL_PRODUCAO_PARAMETRIZACAO = 'https://adn.nfse.gov.br/parametrizacao';

    // URLs de Homologação
    private const URL_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';
    private const URL_HOMOLOGACAO_DANFSE = 'https://adn.producaorestrita.nfse.gov.br/danfse';
    private const URL_HOMOLOGACAO_CONSULTA_PUBLICA = '';
    private const URL_HOMOLOGACAO_PARAMETRIZACAO = 'https://adn.producaorestrita.nfse.gov.br/parametrizacao';

    // Namespace da NFS-e
    private const NS_NFSE = 'http://www.sped.fazenda.gov.br/nfse';

    // Versão do aplicativo
    private const VER_APLIC = 'ApiNfse_v1.0';

    private Client $client;
    private int $tpAmb;
    private string $urlBase;
    private string $urlDanfse;
    private string $urlConsultaPublica;
    private string $urlParametrizacao;
    private ?string $certPath = null;
    private ?string $certPassword = null;
    private ?string $certContent = null;

    /**
     * Construtor da classe
     * 
     * @param array $config Configurações do certificado digital
     *   - cert_path: Caminho do arquivo .pfx/.p12
     *   - cert_password: Senha do certificado
     *   - cert_content: Conteúdo do certificado em base64 (alternativa ao cert_path)
     * @param int $tpAmb Tipo de ambiente (1=Produção, 2=Homologação)
     */
    public function __construct(array $config, int $tpAmb = 2)
    {
        $this->tpAmb = $tpAmb;

        if ($tpAmb === 1) {
            $this->urlBase = self::URL_PRODUCAO;
            $this->urlDanfse = self::URL_PRODUCAO_DANFSE;
            $this->urlConsultaPublica = self::URL_PRODUCAO_CONSULTA_PUBLICA;
            $this->urlParametrizacao = self::URL_PRODUCAO_PARAMETRIZACAO;
        } else {
            $this->urlBase = self::URL_HOMOLOGACAO;
            $this->urlDanfse = self::URL_HOMOLOGACAO_DANFSE;
            $this->urlConsultaPublica = self::URL_HOMOLOGACAO_CONSULTA_PUBLICA;
            $this->urlParametrizacao = self::URL_HOMOLOGACAO_PARAMETRIZACAO;
        }

        $this->certPath = $config['cert_path'] ?? null;
        $this->certPassword = $config['cert_password'] ?? null;
        $this->certContent = $config['cert_content'] ?? null;

        $this->initClient();
    }

    /**
     * Inicializa o cliente HTTP com certificado digital (mTLS)
     */
    private function initClient(): void
    {
        $options = [
            'base_uri' => $this->urlBase,
            'timeout' => 60,
            'verify' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'ApiNfse-PHP/1.0'
            ]
        ];

        if ($this->certPath && $this->certPassword) {
            $options['cert'] = [$this->certPath, $this->certPassword];
        }

        $this->client = new Client($options);
    }

    // =========================================================================
    // MÉTODOS PRINCIPAIS DA API
    // =========================================================================

    /**
     * Envia uma DPS (Declaração de Prestação de Serviços) para gerar NFS-e
     * POST /nfse
     * 
     * @param string $xmlDps XML da DPS assinado
     * @return array Resultado da operação
     */
    public function enviarDPS(string $xmlDps): array
    {
        try {
            // Adiciona declaração XML se não existir
            if (strpos($xmlDps, '<?xml') === false) {
                $xmlDps = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlDps;
            }

            // Compacta e codifica em Base64
            $dpsXmlGZipB64 = $this->toGzipBase64($xmlDps);

            // Monta payload JSON
            $payload = json_encode(['dpsXmlGZipB64' => $dpsXmlGZipB64]);

            // Faz requisição POST
            $response = $this->client->post('/nfse', [
                'body' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $retorno = json_decode($body, true);

            if ($statusCode === 201) {
                // Sucesso - decodifica XML da NFS-e
                $xmlNfse = null;
                if (!empty($retorno['nfseXmlGZipB64'])) {
                    $xmlNfse = $this->fromGzipBase64($retorno['nfseXmlGZipB64']);
                }

                return [
                    'codigo' => '000',
                    'mensagem' => 'DPS registrada com sucesso.',
                    'tipoAmbiente' => $retorno['tipoAmbiente'] ?? null,
                    'dataHoraProcessamento' => $retorno['dataHoraProcessamento'] ?? null,
                    'idDps' => $retorno['idDps'] ?? null,
                    'chaveAcesso' => $retorno['chaveAcesso'] ?? null,
                    'xmlNfse' => $xmlNfse,
                    'ndfse' => $xmlNfse ? $this->extrairNDFSe($xmlNfse) : null,
                    'alertas' => $retorno['alertas'] ?? [],
                    'bodyOriginal' => $body
                ];
            }

            return [
                'codigo' => (string)$statusCode,
                'mensagem' => 'Erro ao enviar DPS.',
                'bodyOriginal' => $body
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao enviar DPS');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro ao enviar DPS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Consulta uma DPS pelo IdDPS
     * GET /dps/{idDps}
     * 
     * @param string $idDps ID da DPS (42 dígitos numéricos)
     * @return array Resultado da consulta
     */
    public function consultarDPS(string $idDps): array
    {
        try {
            // Normaliza IdDPS (remove prefixos)
            $idDps = str_replace(['DPS', 'NFS'], '', $idDps);
            $idDps = trim($idDps);

            if (strlen($idDps) !== 42 || !ctype_digit($idDps)) {
                return [
                    'codigo' => '400',
                    'mensagem' => 'IdDPS inválido. Deve conter 42 números.'
                ];
            }

            $response = $this->client->get("/dps/{$idDps}");
            $body = $response->getBody()->getContents();
            $retorno = json_decode($body, true);

            return [
                'codigo' => '000',
                'mensagem' => 'DPS encontrada.',
                'tipoAmbiente' => $retorno['tipoAmbiente'] ?? null,
                'versaoAplicativo' => $retorno['versaoAplicativo'] ?? null,
                'dataHoraProcessamento' => $retorno['dataHoraProcessamento'] ?? null,
                'chaveAcesso' => $retorno['chaveAcesso'] ?? null,
                'bodyOriginal' => $body
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao consultar DPS');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro ao consultar DPS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Consulta uma NFS-e pela chave de acesso
     * GET /nfse/{chaveAcesso}
     * 
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return array Resultado da consulta com XML da NFS-e
     */
    public function consultarNFSe(string $chaveAcesso): array
    {
        try {
            $chaveAcesso = trim($chaveAcesso);

            $response = $this->client->get("/nfse/{$chaveAcesso}");
            $body = $response->getBody()->getContents();
            $retorno = json_decode($body, true);

            $xmlNfse = null;
            if (!empty($retorno['nfseXmlGZipB64'])) {
                $xmlNfse = $this->fromGzipBase64($retorno['nfseXmlGZipB64']);
            }

            return [
                'codigo' => '000',
                'mensagem' => 'NFS-e encontrada.',
                'tipoAmbiente' => $retorno['tipoAmbiente'] ?? null,
                'versaoAplicativo' => $retorno['versaoAplicativo'] ?? null,
                'dataHoraProcessamento' => $retorno['dataHoraProcessamento'] ?? null,
                'chaveAcesso' => $retorno['chaveAcesso'] ?? null,
                'xmlNfse' => $xmlNfse,
                'ndfse' => $xmlNfse ? $this->extrairNDFSe($xmlNfse) : null,
                'bodyOriginal' => $body
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao consultar NFS-e');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro ao consultar NFS-e: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Cancela uma NFS-e
     * POST /nfse/{chaveAcesso}/eventos
     * 
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string $cnpjAutor CNPJ do autor do cancelamento
     * @param int $cMotivo Código do motivo (1=Erro na emissão, 2=Serviço não prestado, 3=Outros)
     * @param string $xMotivo Descrição do motivo
     * @param string $xmlEventoAssinado XML do pedido de registro de evento assinado
     * @return array Resultado do cancelamento
     */
    public function cancelarNFSe(string $chaveAcesso, string $cnpjAutor, int $cMotivo, string $xMotivo, string $xmlEventoAssinado): array
    {
        try {
            $chaveAcesso = trim($chaveAcesso);

            // Adiciona declaração XML se não existir
            if (strpos($xmlEventoAssinado, '<?xml') === false) {
                $xmlEventoAssinado = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlEventoAssinado;
            }

            // Compacta e codifica em Base64
            $eventoXmlGZipB64 = $this->toGzipBase64($xmlEventoAssinado);

            // Monta payload JSON
            $payload = json_encode(['pedidoRegistroEventoXmlGZipB64' => $eventoXmlGZipB64]);

            $response = $this->client->post("/nfse/{$chaveAcesso}/eventos", [
                'body' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $retorno = json_decode($body, true);

            if ($statusCode === 200 || $statusCode === 201) {
                $xmlEvento = null;
                if (!empty($retorno['eventoXmlGZipB64'])) {
                    $xmlEvento = $this->fromGzipBase64($retorno['eventoXmlGZipB64']);
                }

                return [
                    'codigo' => '000',
                    'mensagem' => 'Cancelamento enviado com sucesso.',
                    'dataHoraProcessamento' => $retorno['dataHoraProcessamento'] ?? null,
                    'codigoRetorno' => $retorno['codigoRetorno'] ?? null,
                    'descricaoRetorno' => $retorno['descricaoRetorno'] ?? null,
                    'xmlEvento' => $xmlEvento,
                    'bodyOriginal' => $body
                ];
            }

            return [
                'codigo' => (string)$statusCode,
                'mensagem' => 'Erro ao cancelar NFS-e.',
                'bodyOriginal' => $body
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao cancelar NFS-e');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro ao cancelar NFS-e: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Consulta eventos de uma NFS-e
     * GET /nfse/{chaveAcesso}/eventos/{tipoEvento}/{numSeqEvento}
     * 
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param int $tipoEvento Tipo do evento (ex: 101101 para cancelamento)
     * @param int $numSeqEvento Número sequencial do evento
     * @return array Resultado da consulta
     */
    public function consultarEventoNFSe(string $chaveAcesso, int $tipoEvento = 101101, int $numSeqEvento = 1): array
    {
        try {
            $chaveAcesso = trim($chaveAcesso);

            $response = $this->client->get("/nfse/{$chaveAcesso}/eventos/{$tipoEvento}/{$numSeqEvento}");
            $body = $response->getBody()->getContents();
            $retorno = json_decode($body, true);

            $eventos = [];
            if (!empty($retorno['eventos'])) {
                foreach ($retorno['eventos'] as $evento) {
                    $xmlEvento = null;
                    if (!empty($evento['arquivoXml'])) {
                        $xmlEvento = $this->decodificarArquivoXml($evento['arquivoXml']);
                    }
                    $eventos[] = [
                        'chaveAcesso' => $evento['chaveAcesso'] ?? null,
                        'tipoEvento' => $evento['tipoEvento'] ?? null,
                        'numeroPedidoRegistroEvento' => $evento['numeroPedidoRegistroEvento'] ?? null,
                        'dataHoraRecebimento' => $evento['dataHoraRecebimento'] ?? null,
                        'xmlEvento' => $xmlEvento
                    ];
                }
            }

            return [
                'codigo' => '000',
                'mensagem' => 'Evento encontrado.',
                'tipoAmbiente' => $retorno['tipoAmbiente'] ?? null,
                'dataHoraProcessamento' => $retorno['dataHoraProcessamento'] ?? null,
                'eventos' => $eventos,
                'bodyOriginal' => $body
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao consultar evento');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro ao consultar evento: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Download do PDF da NFS-e (DANFSE)
     * GET /danfse/{chaveAcesso}
     * 
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @return array Resultado com bytes do PDF
     */
    public function downloadDANFSe(string $chaveAcesso): array
    {
        try {
            $chaveAcesso = trim($chaveAcesso);

            $client = new Client([
                'base_uri' => $this->urlDanfse,
                'timeout' => 60,
                'verify' => true,
                'headers' => [
                    'Accept' => 'application/pdf',
                    'User-Agent' => 'ApiNfse-PHP/1.0'
                ]
            ]);

            if ($this->certPath && $this->certPassword) {
                $client = new Client([
                    'base_uri' => $this->urlDanfse,
                    'timeout' => 60,
                    'verify' => true,
                    'cert' => [$this->certPath, $this->certPassword],
                    'headers' => [
                        'Accept' => 'application/pdf',
                        'User-Agent' => 'ApiNfse-PHP/1.0'
                    ]
                ]);
            }

            $response = $client->get("/{$chaveAcesso}");
            $pdfContent = $response->getBody()->getContents();

            return [
                'codigo' => '000',
                'mensagem' => 'PDF baixado com sucesso.',
                'pdfContent' => $pdfContent,
                'contentType' => $response->getHeaderLine('Content-Type')
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao baixar DANFSE');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro ao baixar DANFSE: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Envia NFS-e com indicativo de decisão judicial (bypass)
     * POST /decisao-judicial/nfse
     * 
     * @param string $xmlNfse XML da NFS-e assinado
     * @return array Resultado da operação
     */
    public function enviarNFSeDecisaoJudicial(string $xmlNfse): array
    {
        try {
            if (strpos($xmlNfse, '<?xml') === false) {
                $xmlNfse = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlNfse;
            }

            $nfseXmlGZipB64 = $this->toGzipBase64($xmlNfse);
            $payload = json_encode(['nfseXmlGZipB64' => $nfseXmlGZipB64]);

            $response = $this->client->post('/decisao-judicial/nfse', [
                'body' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $retorno = json_decode($body, true);

            if ($statusCode === 201) {
                return [
                    'codigo' => '000',
                    'mensagem' => 'NFS-e com decisão judicial registrada com sucesso.',
                    'chaveAcesso' => $retorno['chaveAcesso'] ?? null,
                    'dataHoraProcessamento' => $retorno['dataHoraProcessamento'] ?? null,
                    'bodyOriginal' => $body
                ];
            }

            return [
                'codigo' => (string)$statusCode,
                'mensagem' => 'Erro ao enviar NFS-e com decisão judicial.',
                'bodyOriginal' => $body
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao enviar NFS-e com decisão judicial');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro ao enviar NFS-e com decisão judicial: ' . $e->getMessage()
            ];
        }
    }

    // =========================================================================
    // MÉTODOS PARA CONSTRUÇÃO DO XML DA DPS
    // =========================================================================

    /**
     * Monta o XML da DPS (Declaração de Prestação de Serviços)
     * 
     * @param array $dados Dados da DPS
     * @return string XML da DPS (sem assinatura)
     */
    public function montarXmlDPS(array $dados): string
    {
        $ns = self::NS_NFSE;

        // Dados obrigatórios
        $idDps = $dados['idDps'];
        $dhEmi = $dados['dhEmi'] ?? date('Y-m-d\TH:i:sP');
        $serie = $dados['serie'] ?? '1';
        $nDps = $dados['nDps'] ?? '1';
        $dCompet = $dados['dCompet'] ?? date('Y-m-d');
        $tpEmit = $dados['tpEmit'] ?? '1';
        $cLocEmi = $dados['cLocEmi'];

        // Prestador
        $prest = $dados['prestador'];

        // Tomador
        $toma = $dados['tomador'];

        // Serviço
        $serv = $dados['servico'];

        // Valores
        $valores = $dados['valores'];

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Elemento raiz DPS
        $dps = $xml->createElementNS($ns, 'DPS');
        $dps->setAttribute('versao', '1.00');
        $xml->appendChild($dps);

        // infDPS
        $infDPS = $xml->createElement('infDPS');
        $infDPS->setAttribute('Id', $idDps);
        $dps->appendChild($infDPS);

        // Campos obrigatórios
        $this->addElement($xml, $infDPS, 'tpAmb', (string)$this->tpAmb);
        $this->addElement($xml, $infDPS, 'dhEmi', $dhEmi);
        $this->addElement($xml, $infDPS, 'verAplic', self::VER_APLIC);
        $this->addElement($xml, $infDPS, 'serie', $serie);
        $this->addElement($xml, $infDPS, 'nDPS', $nDps);
        $this->addElement($xml, $infDPS, 'dCompet', $dCompet);
        $this->addElement($xml, $infDPS, 'tpEmit', $tpEmit);
        $this->addElement($xml, $infDPS, 'cLocEmi', $cLocEmi);

        // Prestador
        $prestEl = $xml->createElement('prest');
        $infDPS->appendChild($prestEl);

        if (!empty($prest['cnpj'])) {
            $this->addElement($xml, $prestEl, 'CNPJ', $this->apenasNumeros($prest['cnpj']));
        }
        if (!empty($prest['im'])) {
            $this->addElement($xml, $prestEl, 'IM', $prest['im']);
        }
        if (!empty($prest['fone'])) {
            $this->addElement($xml, $prestEl, 'fone', $this->apenasNumeros($prest['fone']));
        }
        if (!empty($prest['email'])) {
            $this->addElement($xml, $prestEl, 'email', $prest['email']);
        }

        // Regime tributário do prestador
        if (!empty($prest['regTrib'])) {
            $regTrib = $xml->createElement('regTrib');
            $prestEl->appendChild($regTrib);

            if (isset($prest['regTrib']['opSimpNac'])) {
                $this->addElement($xml, $regTrib, 'opSimpNac', (string)$prest['regTrib']['opSimpNac']);
            }
            if (!empty($prest['regTrib']['regApTribSN'])) {
                $this->addElement($xml, $regTrib, 'regApTribSN', (string)$prest['regTrib']['regApTribSN']);
            }
            if (isset($prest['regTrib']['regEspTrib'])) {
                $this->addElement($xml, $regTrib, 'regEspTrib', (string)$prest['regTrib']['regEspTrib']);
            }
        }

        // Tomador
        $tomaEl = $xml->createElement('toma');
        $infDPS->appendChild($tomaEl);

        if (!empty($toma['cnpj'])) {
            $this->addElement($xml, $tomaEl, 'CNPJ', $this->apenasNumeros($toma['cnpj']));
        }
        if (!empty($toma['cpf'])) {
            $this->addElement($xml, $tomaEl, 'CPF', $this->apenasNumeros($toma['cpf']));
        }
        if (!empty($toma['xNome'])) {
            $this->addElement($xml, $tomaEl, 'xNome', $toma['xNome']);
        }

        // Endereço do tomador
        if (!empty($toma['endereco'])) {
            $endEl = $xml->createElement('end');
            $tomaEl->appendChild($endEl);

            $endNac = $xml->createElement('endNac');
            $endEl->appendChild($endNac);

            if (!empty($toma['endereco']['cMun'])) {
                $this->addElement($xml, $endNac, 'cMun', $toma['endereco']['cMun']);
            }
            if (!empty($toma['endereco']['cep'])) {
                $this->addElement($xml, $endNac, 'CEP', $this->apenasNumeros($toma['endereco']['cep']));
            }

            if (!empty($toma['endereco']['xLgr'])) {
                $this->addElement($xml, $endEl, 'xLgr', $this->removerAcentos($toma['endereco']['xLgr']));
            }
            if (!empty($toma['endereco']['nro'])) {
                $this->addElement($xml, $endEl, 'nro', $toma['endereco']['nro']);
            }
            if (!empty($toma['endereco']['xBairro'])) {
                $this->addElement($xml, $endEl, 'xBairro', $this->removerAcentos($toma['endereco']['xBairro']));
            }
        }

        if (!empty($toma['fone'])) {
            $this->addElement($xml, $tomaEl, 'fone', $this->apenasNumeros($toma['fone']));
        }
        if (!empty($toma['email'])) {
            $this->addElement($xml, $tomaEl, 'email', $toma['email']);
        }

        // Serviço
        $servEl = $xml->createElement('serv');
        $infDPS->appendChild($servEl);

        // Local de prestação
        $locPrest = $xml->createElement('locPrest');
        $servEl->appendChild($locPrest);
        $this->addElement($xml, $locPrest, 'cLocPrestacao', $serv['cLocPrestacao']);

        // Código do serviço
        $cServ = $xml->createElement('cServ');
        $servEl->appendChild($cServ);
        $this->addElement($xml, $cServ, 'cTribNac', str_replace('.', '', $serv['cTribNac']));
        $this->addElement($xml, $cServ, 'xDescServ', $serv['xDescServ']);

        // Informações complementares
        if (!empty($serv['xInfComp'])) {
            $infoCompl = $xml->createElement('infoCompl');
            $servEl->appendChild($infoCompl);
            $this->addElement($xml, $infoCompl, 'xInfComp', $serv['xInfComp']);
        }

        // Valores
        $valoresEl = $xml->createElement('valores');
        $infDPS->appendChild($valoresEl);

        // Valor do serviço prestado
        $vServPrest = $xml->createElement('vServPrest');
        $valoresEl->appendChild($vServPrest);
        $this->addElement($xml, $vServPrest, 'vServ', $this->formatarValor($valores['vServ']));

        // Tributos
        $trib = $xml->createElement('trib');
        $valoresEl->appendChild($trib);

        // Tributos municipais (ISSQN)
        $tribMun = $xml->createElement('tribMun');
        $trib->appendChild($tribMun);

        $this->addElement($xml, $tribMun, 'tribISSQN', (string)($valores['tribISSQN'] ?? '1'));
        $this->addElement($xml, $tribMun, 'tpRetISSQN', (string)($valores['tpRetISSQN'] ?? '1'));

        if (!empty($valores['pAliq'])) {
            $this->addElement($xml, $tribMun, 'pAliq', $this->formatarValor($valores['pAliq']));
        }

        // Tributos federais (opcional)
        if (!empty($valores['tribFed'])) {
            $tribFed = $xml->createElement('tribFed');
            $trib->appendChild($tribFed);

            if (!empty($valores['tribFed']['piscofins'])) {
                $piscofins = $xml->createElement('piscofins');
                $tribFed->appendChild($piscofins);
                $this->addElement($xml, $piscofins, 'CST', $valores['tribFed']['piscofins']['CST']);
            }
        }

        // Total de tributos
        if (!empty($valores['pTotTribSN'])) {
            $totTrib = $xml->createElement('totTrib');
            $trib->appendChild($totTrib);
            $this->addElement($xml, $totTrib, 'pTotTribSN', $this->formatarValor($valores['pTotTribSN']));
        }

        return $xml->saveXML();
    }

    /**
     * Monta o XML do Pedido de Registro de Evento (cancelamento)
     * 
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string $cnpjAutor CNPJ do autor
     * @param int $cMotivo Código do motivo (1, 2 ou 3)
     * @param string $xMotivo Descrição do motivo
     * @return string XML do pedido de evento (sem assinatura)
     */
    public function montarXmlCancelamento(string $chaveAcesso, string $cnpjAutor, int $cMotivo, string $xMotivo): string
    {
        $ns = self::NS_NFSE;
        $codEvento = '101101';

        $idInfPed = "PRE{$chaveAcesso}{$codEvento}";
        $dhEvento = date('Y-m-d\TH:i:sP');

        $xDesc = 'Cancelamento de NFS-e';
        switch ($cMotivo) {
            case 1:
                $motivoDescricao = 'Erro na emissao';
                break;
            case 2:
                $motivoDescricao = 'Servico nao prestado';
                break;
            default:
                $motivoDescricao = 'Outros';
        }

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $pedRegEvento = $xml->createElementNS($ns, 'pedRegEvento');
        $pedRegEvento->setAttribute('versao', '1.00');
        $xml->appendChild($pedRegEvento);

        $infPedReg = $xml->createElement('infPedReg');
        $infPedReg->setAttribute('Id', $idInfPed);
        $pedRegEvento->appendChild($infPedReg);

        $this->addElement($xml, $infPedReg, 'tpAmb', (string)$this->tpAmb);
        $this->addElement($xml, $infPedReg, 'verAplic', self::VER_APLIC);
        $this->addElement($xml, $infPedReg, 'dhEvento', $dhEvento);
        $this->addElement($xml, $infPedReg, 'CNPJAutor', $this->apenasNumeros($cnpjAutor));
        $this->addElement($xml, $infPedReg, 'chNFSe', $chaveAcesso);

        $e101101 = $xml->createElement('e101101');
        $infPedReg->appendChild($e101101);

        $this->addElement($xml, $e101101, 'xDesc', $xDesc);
        $this->addElement($xml, $e101101, 'cMotivo', (string)$cMotivo);
        $this->addElement($xml, $e101101, 'xMotivo', $xMotivo ?: $motivoDescricao);

        return $xml->saveXML();
    }

    /**
     * Gera o IdDPS conforme padrão nacional
     * Formato: DPS + cMun(7) + tpInsc(1) + CNPJ/CPF(14) + serie(5) + nDPS(15)
     * 
     * @param string $cMun Código IBGE do município (7 dígitos)
     * @param string $cnpjCpf CNPJ ou CPF do prestador
     * @param int $serie Série da DPS
     * @param int $nDps Número da DPS
     * @return string IdDPS com 45 caracteres
     */
    public function gerarIdDPS(string $cMun, string $cnpjCpf, int $serie, int $nDps): string
    {
        $cnpjCpf = $this->apenasNumeros($cnpjCpf);
        $tpInsc = strlen($cnpjCpf) === 14 ? '1' : '2';

        // Padroniza CNPJ/CPF para 14 dígitos
        $cnpjCpf = str_pad($cnpjCpf, 14, '0', STR_PAD_LEFT);

        // Padroniza série para 5 dígitos
        $serieStr = str_pad((string)$serie, 5, '0', STR_PAD_LEFT);

        // Padroniza número para 15 dígitos
        $nDpsStr = str_pad((string)$nDps, 15, '0', STR_PAD_LEFT);

        return "DPS{$cMun}{$tpInsc}{$cnpjCpf}{$serieStr}{$nDpsStr}";
    }

    // =========================================================================
    // MÉTODOS AUXILIARES
    // =========================================================================

    /**
     * Compacta string com GZip e codifica em Base64
     */
    private function toGzipBase64(string $data): string
    {
        $compressed = gzencode($data, 9);
        return base64_encode($compressed);
    }

    /**
     * Decodifica Base64 e descompacta GZip
     */
    private function fromGzipBase64(string $data): string
    {
        $decoded = base64_decode($data);
        return gzdecode($decoded);
    }

    /**
     * Decodifica arquivo XML (pode vir em Base64 ou Base64+GZip)
     */
    private function decodificarArquivoXml(string $arquivoXml): string
    {
        $arquivoXml = trim($arquivoXml);

        // Se já é XML, retorna
        if (str_starts_with($arquivoXml, '<')) {
            return $arquivoXml;
        }

        // Remove prefixo data: se existir
        if (str_starts_with($arquivoXml, 'data:')) {
            $pos = strpos($arquivoXml, ',');
            if ($pos !== false) {
                $arquivoXml = substr($arquivoXml, $pos + 1);
            }
        }

        // Tenta decodificar Base64
        $decoded = base64_decode($arquivoXml, true);
        if ($decoded === false) {
            return $arquivoXml;
        }

        // Verifica se é GZip (magic bytes 1F 8B)
        if (strlen($decoded) >= 2 && ord($decoded[0]) === 0x1F && ord($decoded[1]) === 0x8B) {
            $decoded = gzdecode($decoded);
        }

        return $decoded ?: $arquivoXml;
    }

    /**
     * Extrai o número da NFS-e (nDFSe) do XML
     */
    private function extrairNDFSe(string $xmlNfse): ?string
    {
        try {
            $dom = new DOMDocument();
            $dom->loadXML($xmlNfse);

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('nfse', self::NS_NFSE);

            $nodes = $xpath->query('//nfse:nDFSe');
            if ($nodes->length > 0) {
                return $nodes->item(0)->nodeValue;
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Trata exceções de requisição HTTP
     */
    private function tratarExcecaoRequest(RequestException $e, string $prefixo): array
    {
        $httpStatus = 0;
        $bodyErro = '';
        $codErro = '';
        $msgErro = $e->getMessage();

        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $httpStatus = $response->getStatusCode();
            $bodyErro = $response->getBody()->getContents();

            // Tenta extrair erro do JSON
            $retornoErro = json_decode($bodyErro, true);
            if ($retornoErro) {
                if (!empty($retornoErro['erro'])) {
                    $codErro = $retornoErro['erro']['codigo'] ?? '';
                    $msgErro = $retornoErro['erro']['descricao'] ?? $msgErro;
                } elseif (!empty($retornoErro['erros']) && is_array($retornoErro['erros'])) {
                    $primeiroErro = $retornoErro['erros'][0] ?? [];
                    $codErro = $primeiroErro['Codigo'] ?? $primeiroErro['codigo'] ?? '';
                    $msgErro = $primeiroErro['Descricao'] ?? $primeiroErro['descricao'] ?? $msgErro;
                    if (!empty($primeiroErro['Complemento'] ?? $primeiroErro['complemento'])) {
                        $msgErro .= ' - ' . ($primeiroErro['Complemento'] ?? $primeiroErro['complemento']);
                    }
                }
            }
        }

        return [
            'codigo' => $httpStatus > 0 ? (string)$httpStatus : '999',
            'mensagem' => "{$prefixo}: " . ($codErro ? "{$codErro} - " : '') . $msgErro,
            'codigoErro' => $codErro,
            'bodyOriginal' => $bodyErro
        ];
    }

    /**
     * Adiciona elemento ao XML
     */
    private function addElement(DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        if ($value !== '') {
            $element = $dom->createElement($name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
            $parent->appendChild($element);
        }
    }

    /**
     * Remove caracteres não numéricos
     */
    private function apenasNumeros(string $valor): string
    {
        return preg_replace('/[^0-9]/', '', $valor);
    }

    /**
     * Formata valor decimal para 2 casas
     */
    private function formatarValor($valor): string
    {
        return number_format((float)$valor, 2, '.', '');
    }

    /**
     * Remove acentos de uma string
     */
    private function removerAcentos(string $string): string
    {
        $acentos = [
            'á', 'à', 'ã', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï',
            'ó', 'ò', 'õ', 'ô', 'ö', 'ú', 'ù', 'û', 'ü', 'ç', 'ñ',
            'Á', 'À', 'Ã', 'Â', 'Ä', 'É', 'È', 'Ê', 'Ë', 'Í', 'Ì', 'Î', 'Ï',
            'Ó', 'Ò', 'Õ', 'Ô', 'Ö', 'Ú', 'Ù', 'Û', 'Ü', 'Ç', 'Ñ'
        ];
        $semAcentos = [
            'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i',
            'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n',
            'A', 'A', 'A', 'A', 'A', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I',
            'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'C', 'N'
        ];

        return str_replace($acentos, $semAcentos, $string);
    }

    /**
     * Retorna a URL de consulta pública da NFS-e
     */
    public function getUrlConsultaPublica(string $chaveAcesso): string
    {
        if (empty($this->urlConsultaPublica)) {
            return '';
        }
        return "{$this->urlConsultaPublica}/?tpc=1&chave={$chaveAcesso}";
    }

    /**
     * Retorna o tipo de ambiente atual
     */
    public function getTipoAmbiente(): int
    {
        return $this->tpAmb;
    }

    /**
     * Retorna a URL base atual
     */
    public function getUrlBase(): string
    {
        return $this->urlBase;
    }
}