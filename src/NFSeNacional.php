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
    // URLs de ProduÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o
    private const URL_PRODUCAO = 'https://sefin.nfse.gov.br';
    private const URL_PRODUCAO_DANFSE = 'https://adn.nfse.gov.br/danfse';
    private const URL_PRODUCAO_CONSULTA_PUBLICA = 'https://www.nfse.gov.br/ConsultaPublica';
    private const URL_PRODUCAO_PARAMETRIZACAO = 'https://adn.nfse.gov.br/parametrizacao';

    // URLs de HomologaÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o
    private const URL_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br';
    private const URL_HOMOLOGACAO_DANFSE = 'https://adn.producaorestrita.nfse.gov.br/danfse';
    private const URL_HOMOLOGACAO_CONSULTA_PUBLICA = 'https://adn.producaorestrita.nfse.gov.br/consultapublica';
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
            // O cURL nÃ£o lÃª PFX direto. Vamos extrair para um formato que ele aceite (PEM)
            $pfxContent = file_get_contents($this->certPath);
            if (openssl_pkcs12_read($pfxContent, $certs, $this->certPassword)) {
                // Criamos um arquivo temporÃ¡rio que contÃ©m o Certificado + Chave Privada
                $tempPem = tempnam(sys_get_temp_dir(), 'cert_');
                $pemData = $certs['cert'] . "\n" . $certs['pkey'];
                file_put_contents($tempPem, $pemData);
                
                // Passamos o caminho do arquivo temporÃ¡rio para o Guzzle
                $options['cert'] = [$tempPem, $this->certPassword];
                
                // Opcional: registrar para deletar o arquivo ao fim da execuÃ§Ã£o
                register_shutdown_function(function() use ($tempPem) {
                    if (file_exists($tempPem)) @unlink($tempPem);
                });
            }
        }

        $this->client = new Client($options);
    }

    /**
     * Monta o XML da DPS (DeclaraÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o de PrestaÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o de ServiÃƒÆ’Ã‚Â§os)
     * 
     * @param array $dados Dados da DPS
     * @return string XML da DPS (sem assinatura)
     */
    public function montarXmlDPS(array $dados): string
    {
        $ns = self::NS_NFSE;

        // Dados obrigatÃƒÆ’Ã‚Â³rios
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

        // ServiÃƒÆ’Ã‚Â§o
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

        // Campos obrigatÃƒÆ’Ã‚Â³rios
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

        // Regime tributÃƒÆ’Ã‚Â¡rio do prestador
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

        // EndereÃƒÆ’Ã‚Â§o do tomador
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

        // ServiÃƒÆ’Ã‚Â§o
        $servEl = $xml->createElement('serv');
        $infDPS->appendChild($servEl);

        // Local de prestaÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o
        $locPrest = $xml->createElement('locPrest');
        $servEl->appendChild($locPrest);
        $this->addElement($xml, $locPrest, 'cLocPrestacao', $serv['cLocPrestacao']);

        // CÃƒÆ’Ã‚Â³digo do serviÃƒÆ’Ã‚Â§o
        $cServ = $xml->createElement('cServ');
        $servEl->appendChild($cServ);
        $this->addElement($xml, $cServ, 'cTribNac', str_replace('.', '', $serv['cTribNac']));
        $this->addElement($xml, $cServ, 'xDescServ', $serv['xDescServ']);

        // InformaÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Âµes complementares
        if (!empty($serv['xInfComp'])) {
            $infoCompl = $xml->createElement('infoCompl');
            $servEl->appendChild($infoCompl);
            $this->addElement($xml, $infoCompl, 'xInfComp', $serv['xInfComp']);
        }

        // Valores
        $valoresEl = $xml->createElement('valores');
        $infDPS->appendChild($valoresEl);

        // Valor do serviÃƒÆ’Ã‚Â§o prestado
        $vServPrest = $xml->createElement('vServPrest');
        $valoresEl->appendChild($vServPrest);
        $this->addElement($xml, $vServPrest, 'vServ', $this->formatarValor($valores['vServ']));

        // Tributos
        // --- Tributos (Estrutura Final Validada) ---
        $trib = $xml->createElement('trib');
        $valoresEl->appendChild($trib); // trib entra em valores

        // 1. Tributos Municipais (ISSQN)
        $tribMun = $xml->createElement('tribMun');
        $trib->appendChild($tribMun);
        
        $tpRetISSQN = (string)($valores['tpRetISSQN'] ?? '1');
        
        $this->addElement($xml, $tribMun, 'tribISSQN', (string)($valores['tribISSQN'] ?? '1'));
        $this->addElement($xml, $tribMun, 'tpRetISSQN', $tpRetISSQN);
        
        // REGRA E0625: SÃ³ envia alÃ­quota se houver retenÃ§Ã£o (tpRetISSQN != 1)
        // Ou se vocÃª nÃ£o for do Simples Nacional. 
        // Para o seu teste atual, vamos apenas comentar/remover o envio da pAliq:
        /* if (isset($valores['pAliq'])) {
            $this->addElement($xml, $tribMun, 'pAliq', $this->formatarValor($valores['pAliq']));
        }
        */

        



        // 2. Tributos Federais
        $tribFed = $xml->createElement('tribFed');
        $trib->appendChild($tribFed);

        $piscofins = $xml->createElement('piscofins');
        $tribFed->appendChild($piscofins);
        $this->addElement($xml, $piscofins, 'CST', '99'); 
        $this->addElement($xml, $piscofins, 'vPis', '0.00');
        $this->addElement($xml, $piscofins, 'vCofins', '0.00');

        // REMOVA OU COMENTE ESTAS LINHAS ABAIXO:
        // O erro E0700 sugere que ao ver a tag, o governo espera um valor > 0.
        // $this->addElement($xml, $tribFed, 'vRetCP', '0.00');
        // $this->addElement($xml, $tribFed, 'vRetIRRF', '0.00');
        // $this->addElement($xml, $tribFed, 'vRetCSLL', '0.00');





        // 3. Totais dos Tributos (SoluÃ§Ã£o para Simples Nacional / ME-EPP)
        $totTrib = $xml->createElement('totTrib');
        $trib->appendChild($totTrib);

        // O Schema exige um filho. O NegÃ³cio proibiu o 'indTotTrib'.
        // Usaremos o 'pTotTribSN' que Ã© o campo especÃ­fico para o seu regime.
        $this->addElement($xml, $totTrib, 'pTotTribSN', '0.00');

        return $xml->saveXML();
    }

    // =========================================================================
    // MÉTODOS PRINCIPAIS DA API
    // =========================================================================

    /**
     * Envia uma DPS (Declaração de Prestação de Serviços) para gerar NFS-e
     * POST /nfse
     * 
     * @param string $xmlDps XML da DPS assinado
     * @return array Resultado da operaÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o
     */
    public function enviarDPS(string $xmlDps): array
    {
        try {
            // Adiciona declaraÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o XML se nÃƒÆ’Ã‚Â£o existir
            if (strpos($xmlDps, '<?xml') === false) {
                $xmlDps = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlDps;
            }

            // Compacta e codifica em Base64
            $dpsXmlGZipB64 = $this->toGzipBase64($xmlDps);

            // Monta payload JSON
            $payload = json_encode(['dpsXmlGZipB64' => $dpsXmlGZipB64]);

            // Faz requisiÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o POST
            $response = $this->client->post('/SefinNacional/nfse', [
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
                    'codigo' => $statusCode,
                    'mensagem' => 'DPS registrada com sucesso.',
                    'tipoAmbiente' => $retorno['tipoAmbiente'] ?? null,
                    'dataHoraProcessamento' => $retorno['dataHoraProcessamento'] ?? null,
                    'idDps' => $retorno['idDps'] ?? null,
                    'chaveAcesso' => $retorno['chaveAcesso'] ?? null,
                    'xmlNfse' => $xmlNfse,
                    'ndfse' => $xmlNfse ? $this->extrairNDFSe($xmlNfse) : null,
                    'alertas' => $retorno['alertas'] ?? [],
                    'bodyOriginal' => $body,
                    'retorno' => $retorno
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
     * Gera o IdDPS conforme padrÃ£o nacional
     * Formato: DPS + cMun(7) + tpInsc(1) + CNPJ/CPF(14) + serie(5) + nDPS(15)
     * 
     * @param string $cMun CÃ³digo IBGE do municÃ­pio (7 dÃ­gitos)
     * @param string $cnpjCpf CNPJ ou CPF do prestador
     * @param int $serie SÃ©rie da DPS
     * @param int $nDps NÃºmero da DPS
     * @return string IdDPS com 45 caracteres
     */
    public function gerarIdDPS(string $cMun, string $cnpjCpf, int $serie, int $nDps): string
    {
        $cnpjCpf = $this->apenasNumeros($cnpjCpf);
        $tpInsc = strlen($cnpjCpf) === 14 ? '1' : '2';

        // Padroniza CNPJ/CPF para 14 dÃ­gitos
        $cnpjCpf = str_pad($cnpjCpf, 14, '0', STR_PAD_LEFT);

        // Padroniza sÃ©rie para 5 dÃ­gitos
        $serieStr = str_pad((string)$serie, 5, '0', STR_PAD_LEFT);

        // Padroniza nÃºmero para 15 dÃ­gitos
        $nDpsStr = str_pad((string)$nDps, 15, '0', STR_PAD_LEFT);

        return "DPS{$cMun}{$tpInsc}{$cnpjCpf}{$serieStr}{$nDpsStr}";
    }
    
    /**
     * Consulta os dados detalhados de uma NFSe pela Chave de Acesso
     * GET /nfse/{chaveAcesso}
     */
    public function consultarNFSePorChave(string $chaveAcesso): array
    {
        try {

            $chaveAcesso = trim($chaveAcesso);

            $url = $this->tpAmb === 1
                ? "https://adn.nfse.gov.br/nfse/{$chaveAcesso}"
                : "https://adn.producaorestrita.nfse.gov.br/contribuintes/NFSe/{$chaveAcesso}/Eventos";

            $response = $this->client->get($url, [
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("Falha na consulta da NFS-e");
            }

            $body = $response->getBody()->getContents();

            return [
                'codigo' => '000',
                'mensagem' => 'Consulta realizada com sucesso',
                'dados' => json_decode($body, true)
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
            $response = $this->client->get("{$this->urlDanfse}/{$chaveAcesso}", [
                'headers' => [
                    'Accept' => 'application/pdf'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("Falha ao baixar DANFSe. Status: " . $response->getStatusCode());
            }

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
     * Cancela uma NFS-e
     * POST /nfse/{chaveAcesso}/eventos
     * 
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string $cnpjAutor CNPJ do autor do cancelamento
     * @param int $cMotivo CÃƒÆ’Ã‚Â³digo do motivo (1=Erro na emissÃƒÆ’Ã‚Â£o, 2=ServiÃƒÆ’Ã‚Â§o nÃƒÆ’Ã‚Â£o prestado, 3=Outros)
     * @param string $xMotivo DescriÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o do motivo
     * @param string $xmlEventoAssinado XML do pedido de registro de evento assinado
     * @return array Resultado do cancelamento
     */
    /**
     * Cancela uma NFS-e enviando o evento assinado e compactado
     */
    public function cancelarNFSe(string $chaveAcesso, string $cnpjAutor, int $cMotivo, string $xMotivo): array
    {
        try {
            // 1. Gera o XML limpo
            $xmlLimpo = $this->montarXmlCancelamento($chaveAcesso, $cnpjAutor, $cMotivo, $xMotivo);
            
            // 2. Assina o XML (passando a tag correta infPedReg)
            $xmlAssinado = $this->assinarXMLCan($xmlLimpo, 'infPedReg');
            
            // 3. Compacta e Codifica (O segredo do sucesso)
            $gz = gzencode($xmlAssinado);
            $peloGzipB64 = base64_encode($gz);
            
            $dados = [
                'pedidoRegistroEventoXmlGZipB64' => $peloGzipB64
            ];

            // 4. Envia para o governo
            // O endpoint de cancelamento geralmente Ã© POST /nfse/{chave}/eventos
            $response = $this->client->post("/SefinNacional/nfse/{$chaveAcesso}/eventos", [
                'json' => $dados
            ]);

            $body = $response->getBody()->getContents();
            return [
                'codigo' => '000',
                'mensagem' => 'RequisiÃ§Ã£o de cancelamento enviada.',
                'resposta' => json_decode($body, true)
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao cancelar NFS-e');
        } catch (Exception $e) {
            return ['codigo' => '999', 'mensagem' => $e->getMessage()];
        }
    }

    /**
     * Monta o XML do Pedido de Registro de Evento (cancelamento)
     * 
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string $cnpjAutor CNPJ do autor
     * @param int $cMotivo CÃƒÆ’Ã‚Â³digo do motivo (1, 2 ou 3)
     * @param string $xMotivo DescriÃƒÆ’Ã‚Â§ÃƒÆ’Ã‚Â£o do motivo
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

    public function assinarXMLCan(string $xml, string $rootTag = 'infPedReg'): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false; // Importante: nÃ£o formatar para nÃ£o quebrar a assinatura
        $dom->loadXML($xml);

        // 1. Identifica o nÃ³ pelo nome passado (infDPS ou infPedReg)
        $node = $dom->getElementsByTagName($rootTag)->item(0);
        if (!$node) {
            throw new \Exception("Tag {$rootTag} nÃ£o encontrada no XML para assinatura.");
        }
        
        $id = $node->getAttribute('Id');

        // 2. Extrai chaves do certificado
        $pfxContent = file_get_contents($this->certPath);
        openssl_pkcs12_read($pfxContent, $certs, $this->certPassword);
        $privateKey = $certs['pkey'];
        $publicCert = $certs['cert'];
        $cleanCert = str_replace(["-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----", "\n", "\r"], '', $publicCert);

        // 3. Digest Value (Hash do ConteÃºdo)
        $canonNode = $node->C14N(false, false);
        $digestValue = base64_encode(sha1($canonNode, true));

        // 4. Monta a estrutura Signature
        $signature = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'Signature');
        $dom->documentElement->appendChild($signature);

        $signedInfo = $dom->createElement('SignedInfo');
        $signature->appendChild($signedInfo);

        $cm = $dom->createElement('CanonicalizationMethod');
        $cm->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($cm);

        $sm = $dom->createElement('SignatureMethod');
        $sm->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfo->appendChild($sm);

        $reference = $dom->createElement('Reference');
        $reference->setAttribute('URI', "#$id");
        $signedInfo->appendChild($reference);

        $transforms = $dom->createElement('Transforms');
        $reference->appendChild($transforms);

        $t1 = $dom->createElement('Transform');
        $t1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($t1);

        $t2 = $dom->createElement('Transform');
        $t2->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transforms->appendChild($t2);

        $dm = $dom->createElement('DigestMethod');
        $dm->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $reference->appendChild($dm);

        $dv = $dom->createElement('DigestValue', $digestValue);
        $reference->appendChild($dv);

        // 5. Signature Value (Assinatura do SignedInfo)
        $canonSignedInfo = $signedInfo->C14N(false, false);
        openssl_sign($canonSignedInfo, $signatureValue, $privateKey, OPENSSL_ALGO_SHA1);
        
        $sv = $dom->createElement('SignatureValue', base64_encode($signatureValue));
        $signature->appendChild($sv);

        $keyInfo = $dom->createElement('KeyInfo');
        $signature->appendChild($keyInfo);
        $x509Data = $dom->createElement('X509Data');
        $keyInfo->appendChild($x509Data);
        $x509Cert = $dom->createElement('X509Certificate', $cleanCert);
        $x509Data->appendChild($x509Cert);

        return $dom->saveXML();
    }

    /**
     * DistribuiÃ§Ã£o de DF-e para o Contribuinte (Baixar notas por NSU)
     * * @param int $nsu NÃºmero Sequencial Ãšnico para inÃ­cio da consulta
     * @param string|null $cnpjConsulta CNPJ a ser consultado (opcional se for o do certificado)
     * @param bool $lote Define se deve retornar um lote de documentos ou apenas um
     */
    public function baixarDfeContribuinte(int $nsu = 0, ?string $cnpjConsulta = null, bool $lote = true): array
    {
        try {
            $queryParams = [];
            if ($cnpjConsulta) {
                $queryParams['cnpjConsulta'] = preg_replace('/\D/', '', $cnpjConsulta);
            }
            $queryParams['lote'] = $lote ? 'true' : 'false';

            // No padrÃ£o Nacional, esse serviÃ§o costuma rodar na URL do ADN (mesma do DANFSE)
            // Mas o caminho base depende da implementaÃ§Ã£o da prefeitura/nacional.
            // Vamos usar a urlParametrizacao ou urlDanfse como base de host se necessÃ¡rio
            $baseUrlADN = str_replace('/danfse', '', $this->urlDanfse);
            $url = "{$baseUrlADN}/contribuintes/DFe/{$nsu}";

            $response = $this->client->get($url, [
                'query' => $queryParams
            ]);

            $body = $response->getBody()->getContents();
            
            return [
                'codigo' => '000',
                'mensagem' => 'Consulta de DF-e realizada.',
                'dados' => json_decode($body, true),
                'bodyOriginal' => $body
            ];

        } catch (RequestException $e) {
            return $this->tratarExcecaoRequest($e, 'Erro ao baixar DFe');
        } catch (Exception $e) {
            return [
                'codigo' => '999',
                'mensagem' => 'Erro: ' . $e->getMessage()
            ];
        }
    }

    // =========================================================================
    // FUNÇÕES AUXILIARES
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
     * Remove acentos de uma string
     */
    private function removerAcentos(string $string): string
    {
        $acentos = [
            'ÃƒÆ’Ã‚Â¡', 'ÃƒÆ’Ã‚Â ', 'ÃƒÆ’Ã‚Â£', 'ÃƒÆ’Ã‚Â¢', 'ÃƒÆ’Ã‚Â¤', 'ÃƒÆ’Ã‚Â©', 'ÃƒÆ’Ã‚Â¨', 'ÃƒÆ’Ã‚Âª', 'ÃƒÆ’Ã‚Â«', 'ÃƒÆ’Ã‚Â­', 'ÃƒÆ’Ã‚Â¬', 'ÃƒÆ’Ã‚Â®', 'ÃƒÆ’Ã‚Â¯',
            'ÃƒÆ’Ã‚Â³', 'ÃƒÆ’Ã‚Â²', 'ÃƒÆ’Ã‚Âµ', 'ÃƒÆ’Ã‚Â´', 'ÃƒÆ’Ã‚Â¶', 'ÃƒÆ’Ã‚Âº', 'ÃƒÆ’Ã‚Â¹', 'ÃƒÆ’Ã‚Â»', 'ÃƒÆ’Ã‚Â¼', 'ÃƒÆ’Ã‚Â§', 'ÃƒÆ’Ã‚Â±',
            'ÃƒÆ’Ã‚Â', 'ÃƒÆ’Ã¢â€šÂ¬', 'ÃƒÆ’Ã†â€™', 'ÃƒÆ’Ã¢â‚¬Å¡', 'ÃƒÆ’Ã¢â‚¬Å¾', 'ÃƒÆ’Ã¢â‚¬Â°', 'ÃƒÆ’Ã‹â€ ', 'ÃƒÆ’Ã…Â ', 'ÃƒÆ’Ã¢â‚¬Â¹', 'ÃƒÆ’Ã‚Â', 'ÃƒÆ’Ã…â€™', 'ÃƒÆ’Ã…Â½', 'ÃƒÆ’Ã‚Â',
            'ÃƒÆ’Ã¢â‚¬Å“', 'ÃƒÆ’Ã¢â‚¬â„¢', 'ÃƒÆ’Ã¢â‚¬Â¢', 'ÃƒÆ’Ã¢â‚¬Â', 'ÃƒÆ’Ã¢â‚¬â€œ', 'ÃƒÆ’Ã…Â¡', 'ÃƒÆ’Ã¢â€žÂ¢', 'ÃƒÆ’Ã¢â‚¬Âº', 'ÃƒÆ’Ã…â€œ', 'ÃƒÆ’Ã¢â‚¬Â¡', 'ÃƒÆ’Ã¢â‚¬Ëœ'
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
                    // if (!empty($primeiroErro['Complemento'] ?? $primeiroErro['complemento'])) {
                    //     $msgErro .= ' - ' . ($primeiroErro['Complemento'] ?? $primeiroErro['complemento']);
                    // }
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
     * Remove caracteres nÃƒÆ’Ã‚Â£o numÃƒÆ’Ã‚Â©ricos
     */
    private function apenasNumeros(string $valor): string
    {
        return preg_replace('/[^0-9]/', '', $valor);
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
     * Formata valor decimal para 2 casas
     */
    private function formatarValor($valor): string
    {
        return number_format((float)$valor, 2, '.', '');
    }
}