<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Service;

use DigitalExchange\AiMailReply\Config\AppConfig;
use DigitalExchange\AiMailReply\Domain\Email;
use DigitalExchange\AiMailReply\Domain\AiResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

class OpenAiService
{
    private AppConfig $config;
    private Logger $logger;
    private Client $client;
    private string $defaultPrompt;

    public function __construct(AppConfig $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $config->getOpenaiConfig()['api_key'],
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->defaultPrompt = $config->getDefaultPrompt();
    }

    /**
     * Generate AI response for email
     * 
     * @param Email $email Email object
     * @return AiResponse AI response
     * @throws \Exception If API request fails
     */
    public function generateResponse(Email $email): AiResponse
    {
        try {
            // Prepare prompt
            $prompt = $this->preparePrompt($email);
            
            // Prepare messages for API
            $messages = [
                [
                    'role' => 'system',
                    'content' => $this->defaultPrompt
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ];

            // Add instruction for response format
            $messages[] = [
                'role' => 'system',
                'content' => "Asla Emoji ve Markdown kullanmadan yanıt verme! Tüm cevaplarında Markdown formatını kullanmalısın ve cevabında emojiler kullanmalısın.
Önemli kelime öbeklerinin altını çizmeli (__altı çizili__), önemli yerleri bold yapmalı (**kalın**), kısa alıntıları yatık yapmalı (*italik*) gibi biçimleri uygulamalısın.
Markdown formatını kullanmalısın. Emojiler için Unicode UTF-8 kullanmalısın. Her başlıkta en az bir emoji kullanmalısın. Örneğin: 👍 🎉 ✅ 😊 👋 🚀 ⚠️ ❗ ❓ ✨ 💡 gibi. Emojiler e-posta içeriğinde görünecek ve mesajı daha canlı hale getirecektir."
            ];

            // TODO: Burası çok riskli bir alan, değerlendirilecek. Kesinlikle çalışmadığına emin olalım
            if ($this->config->allowAiRecipients() && false) {
                $this->logger->warning('allowAiRecipients', [
                    'info' => "allowAiRecipients is enabled. AI recipients are allowed to be used."
                ]);

                // Add instruction for response format
                $messages[] = [
                    'role' => 'system',
                    'content' => "Yanıtını oluştururken, özel talimatlar için JSON formatını kullanabilirsin. Eğer sana e-posta gönderen kişi e-postayı sadece kime göndermen ya da bu e-posta gönderimine eklemen kişileri açık bir şekilde belirttiyse bunları, aşağıdaki gibi bir JSON bloğunda planlayabilirsin:

                    ```json
                    {
                    \"recipients\": [\"ornek@example.com\"],
                    \"cc\": [\"kopya@example.com\"],
                    \"only_to_these_recipients\": true
                    }
                    ```

                    Bu JSON bloğu, yanıtının sonunda yer almalıdır ve normal yanıt metninden ayrı olmalıdır. JSON bloğu olmadan da yanıt verebilirsin, bu durumda varsayılan olarak tüm alıcılara yanıt gönderilecektir.
                    Sana e-posta gönderen bu e-postayı sadece belli kişilere göndermeni ya da belli kişileri eklemen gerektiğini belirtmediyse kesinlikle eposta akışında olan e-postaları toplayıp cevap verme!
                    Hayali e-postalar uydurma. Burada insanlar tarafından hatalı to ve cc'ler yazılabileceği için sana kesin olarak verilen direktiflerin dışına çıkmamalısın."
                ];
            }

            $this->logger->info('Sent messages to AI : ' . print_r($messages, true));

            // Make API request
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $this->config->getOpenaiConfig()['model'],
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'max_tokens' => 7000,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.1,
                    'presence_penalty' => 0.3
                ]
            ]);

            // Parse response
            $responseData = json_decode((string) $response->getBody(), true);

            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw new \Exception('Invalid API response format');
            }

            $aiResponseText = $responseData['choices'][0]['message']['content'];

            // Create AiResponse object
            $aiResponse = AiResponse::fromAiResponseText($aiResponseText);

            $this->logger->info('AI response generated successfully. Response content:', [
                'email_subject' => $email->getSubject(),
                'response_length' => strlen($aiResponseText),
                'response_content' => $aiResponseText,
                'processed_content' => $aiResponse->getContent()
            ]);
            
            return $aiResponse;
        } catch (GuzzleException $e) {
            $this->logger->error('OpenAI API request failed: ' . $e->getMessage());
            throw new \Exception('OpenAI API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Prepare prompt for OpenAI API
     * 
     * @param Email $email Email object
     * @return string Prepared prompt
     */
    private function preparePrompt(Email $email): string
    {
        $prompt = '';

        // Add custom prompt if available
        if ($email->getCustomPrompt() && strlen($email->getCustomPrompt()) === 10) {
            $prompt .= "Özel Yönerge: " . $email->getCustomPrompt() . "\n\n";
        }
        
        // Add current email
        $prompt .= "Son E-posta:\n";
        $prompt .= "Kimden: " . $email->getFromName() . " <" . $email->getFrom() . ">\n";
        $prompt .= "Konu: " . $email->getSubject() . "\n";
        
        // Temizlenmiş ve düzgün formatlanmış içerik
        $cleanBody = $this->cleanEmailContent($email->getBody());
        
        $prompt .= "İçerik:\n" . $cleanBody . "\n\n";
        
        // Add thread emails if available
        if (!empty($email->getThreadEmails())) {
            $prompt .= "Önceki E-postalar:\n";
            
            foreach (array_reverse($email->getThreadEmails()) as $index => $threadEmail) {
                $prompt .= "--- E-posta " . ($index + 1) . " ---\n";
                $prompt .= $threadEmail . "\n\n";
            }
        }
        
        $this->logger->info('Prepare prompt', [
            'prompt' => $prompt
        ]);

        return $prompt;
    }

    /**
     * E-posta içeriğini temizleyerek daha okunaklı ve optimize hale getirir.
     * Çeşitli e-posta platformlarıyla uyumlu çalışır.
     *
     * @param string $content E-posta içeriği
     * @return string Temizlenmiş içerik
     */
    public function cleanEmailContent(string $content): string
    {
        // Farklı karakter kodlamaları için koruma
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content, ['UTF-8', 'ISO-8859-9', 'ISO-8859-1'], true));
        }

        // 1. MIME bölümlerini belirle ve "text/plain" olanı ayıkla (daha güvenilir regex)
        if (preg_match('/Content-Type:\s*text\/plain(?:;|\s).*?(?:\n\n|\r\n\r\n)(.*?)(?=\n--[0-9a-zA-Z]+(?:--)?|\Z)/is', $content, $matches)) {
            $plainText = $matches[1];
            // Başarıyla text/plain içeriği çıkartıldıysa ve yeterince uzunsa kullan
            if (strlen($plainText) > 5) {
                $content = $plainText;
            }
        }
        
        // Eğer multipart mesajsa ve text/plain bulunamadıysa, alternatif yaklaşım
        if (strpos($content, 'Content-Type: multipart/') !== false && !isset($plainText)) {
            // Tüm MIME bölümlerini bul
            preg_match_all('/--.*?\n(.*?)\n\n(.*?)(?=\n--|\Z)/s', $content, $parts, PREG_SET_ORDER);
            
            foreach ($parts as $part) {
                $headers = $part[1];
                $body = $part[2];
                
                // text/plain bölümünü bul, HTML içeren bölümleri atla
                if (strpos($headers, 'text/plain') !== false && strpos($headers, 'text/html') === false) {
                    $content = $body;
                    break;
                }
            }
        }

        // 2. Base64 kodlu ekleri temizle (daha kapsamlı regex)
        $content = preg_replace('/--.*?\nContent-Type: (?:image|application)\/.*?(?:\n.*?)*?Content-Transfer-Encoding: base64.*?\n\n[A-Za-z0-9\/\+\r\n=]+/is', '[EK KALDIRILDI]', $content);
        
        // 3. Inline base64 imajları kaldır
        $content = preg_replace('/Content-ID:.*?\nX-Attachment-Id:.*?\n\n[A-Za-z0-9\/\+\r\n=]+/is', '[GÖRSEL KALDIRILDI]', $content);
        
        // 4. MIME boundary'leri temizle
        $content = preg_replace('/--[0-9a-zA-Z]+(?:--)?\r?\n/i', '', $content);
        
        // 5. Gereksiz başlıkları temizle - daha geniş kapsam
        $headersToRemove = [
            'Content-ID:', 
            'X-Attachment-Id:', 
            'Content-Disposition:', 
            'Content-Transfer-Encoding:', 
            'Content-Type:',
            'MIME-Version:',
            'boundary=',
            'charset='
        ];
        
        foreach ($headersToRemove as $header) {
            $content = preg_replace('/' . preg_quote($header, '/') . '.*?\n/im', '', $content);
        }
        
        // 6. HTML etiketlerini temizle
        $content = strip_tags($content);
        
        // 7. Quoted-printable kodlamasını çöz
        $content = quoted_printable_decode($content);
        
        // 8. Başlık kodlamalarını çöz (=?UTF-8?B? ve =?UTF-8?Q?) - daha kapsamlı
        $content = preg_replace_callback(
            '/=\?([A-Za-z0-9\-]+)\?([BQ])\?(.*?)\?=/is',
            function ($matches) {
                $charset = $matches[1];
                $encoding = $matches[2];
                $text = $matches[3];
                
                if (strtoupper($encoding) === 'B') {
                    $decoded = base64_decode($text);
                } else {
                    $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
                }
                
                // Farklı karakter seti kullanılmışsa dönüştür
                if (strtoupper($charset) !== 'UTF-8') {
                    $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset);
                }
                
                return $decoded;
            },
            $content
        );
        
        // 9. Hex kodlanmış karakterleri çöz (=C4=9F → 'ğ')
        $content = preg_replace_callback(
            '/=([0-9A-F]{2})/i',
            function ($matches) {
                return chr(hexdec($matches[1]));
            },
            $content
        );
        
        // 11. Tekrar eden e-posta imzalarını temizle (daha güvenilir regex)
        $content = preg_replace('/(\n-- \n.*?)(\1+)/s', '$1', $content);
        //$content = preg_replace('/(-- .*?(?:www\..*?|(?:\+\d{1,4}\s?\d+)+)(?:\n|$)\n*){2,}/s', '$1', $content);
        
        // 12. HTML karakter referanslarını çevir (&amp; gibi)
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 13. Fazla boşlukları temizle ve satır sonlarını normalize et
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        
        // 14. Uzun URL'leri kısalt (isteğe bağlı)
        //$content = preg_replace('/https?:\/\/[^\s]{40,}/i', '[URL]', $content);
        
        // 15. Son kontroller ve düzeltmeler
        $content = trim($content);
        
        // Tamamen boş gelirse orijinal içeriği döndür (güvenlik önlemi)
        if (empty($content) || strlen($content) < 10) {
            // Orjinal içeriği basitçe temizle
            $original = strip_tags($content);
            $original = preg_replace('/\n{3,}/', "\n\n", $original);
            return trim($original);
        }

        return $content;
    }

}