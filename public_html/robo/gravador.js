require('dotenv').config({ path: '../.env' });
const { spawn } = require('child_process');
const puppeteer = require('puppeteer');
const mysql = require('mysql2/promise');
const axios = require('axios'); // ADICIONADO PARA COMUNICAR COM O ZAP
const path = require('path');

const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || 'dkingadmin',
    database: process.env.DB_NAME || 'sistema_cliente_1',
    waitForConnections: true,
    connectionLimit: 2,
});

const uploadDir = path.join(__dirname, '../assets/uploads');
let gravadorOcupado = false;

async function iniciarGravador() {
    console.log("🎥 [CINEGRAFISTA] Modo Full-Size Otimizado (1280x720) - COM AUTO-RETRY");

    setInterval(async () => {
        if (gravadorOcupado) return;

        try {
            const [rows] = await pool.execute("SELECT id FROM sorteios WHERE status = 'gravar_video' LIMIT 1");

            if (rows.length > 0) {
                gravadorOcupado = true;
                const idSorteio = rows[0].id;
                console.log(`🎬 [SORTEIO ${idSorteio}] Iniciando processo...`);

                await pool.execute("UPDATE sorteios SET status = 'gravando' WHERE id = ?", [idSorteio]);

                let sucesso = false;

                // LÓGICA DA SUA IDEIA: LOOP DE 2 TENTATIVAS
                for (let tentativa = 1; tentativa <= 2; tentativa++) {
                    let browser = null;
                    let ffmpeg = null;

                    try {
                        browser = await puppeteer.launch({
                            headless: "new",
                            args: [
                                '--no-sandbox',
                                '--disable-setuid-sandbox',
                                '--disable-dev-shm-usage',
                                '--single-process',
                                '--disable-gpu',
                                '--disable-software-rasterizer'
                            ]
                        });

                        const page = await browser.newPage();
                        await page.setViewport({ width: 1280, height: 720 });

                        const videoPath = `${uploadDir}/sorteio_${idSorteio}.mp4`;
                        const imagePath = `${uploadDir}/sorteio_${idSorteio}.jpg`;

                        ffmpeg = spawn('ffmpeg', [
                            '-y', '-f', 'image2pipe', '-vcodec', 'mjpeg', '-framerate', '8', '-i', '-',
                            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-r', '8', '-crf', '32',
                            videoPath
                        ]);

                        console.log(`📡 [SORTEIO ${idSorteio}] [T${tentativa}] Abrindo página do sorteador...`);

                        //  const urlSorteador = `${process.env.APP_URL}/sistema_sorteios/sorteador.php?id=${idSorteio}&t=${Date.now()}`;
                        const urlSorteador = `http://localhost:8002/sorteador.php?id=${idSorteio}&t=${Date.now()}`;
                        // AUMENTADO PARA 4 MINUTOS (240000ms)
                        await page.goto(urlSorteador, {
                            waitUntil: 'domcontentloaded',
                            timeout: 240000
                        });
                        console.log(`✅ [SORTEIO ${idSorteio}] [T${tentativa}] Página aberta.`);

                        let gravando = true;
                        const gravarFrames = async () => {
                            const tempoPorFrame = 125;
                            while (gravando) {
                                const inicio = Date.now();
                                try {
                                    const buffer = await page.screenshot({ type: 'jpeg', quality: 25 });
                                    if (ffmpeg && ffmpeg.stdin.writable) ffmpeg.stdin.write(buffer);
                                } catch (e) { break; }
                                const tempoEspera = Math.max(0, tempoPorFrame - (Date.now() - inicio));
                                await new Promise(r => setTimeout(r, tempoEspera));
                            }
                        };

                        await new Promise(r => setTimeout(r, 2000));
                        console.log(`📸 [SORTEIO ${idSorteio}] [T${tentativa}] Capturando frames...`);
                        gravarFrames();

                        await new Promise(r => setTimeout(r, 2000));
                        console.log(`🔘 [SORTEIO ${idSorteio}] [T${tentativa}] Clicando no Play...`);
                        await page.click('#btnPlay');

                        console.log(`⏳ [SORTEIO ${idSorteio}] [T${tentativa}] Aguardando finalização (timeout 4min)...`);

                        // AUMENTADO PARA 4 MINUTOS (240000ms)
                        await page.waitForSelector('#sorteioFinalizado', { timeout: 240000 });

                        console.log(`🏁 [SORTEIO ${idSorteio}] [T${tentativa}] Sorteio concluído na tela!`);
                        await new Promise(r => setTimeout(r, 1000));
                        await page.screenshot({ path: imagePath, type: 'jpeg', quality: 80 });
                        await new Promise(r => setTimeout(r, 2000));

                        gravando = false;
                        if (ffmpeg) ffmpeg.stdin.end();

                        console.log(`✅ [SORTEIO ${idSorteio}] Vídeo gerado com sucesso!`);
                        await pool.execute("UPDATE sorteios SET status = 'video_pronto' WHERE id = ?", [idSorteio]);
                        sucesso = true;

                        if (browser) await browser.close().catch(() => { });
                        break; // SUCESSO ABSOLUTO! SAI DO LOOP E SEGUE O BAILE

                    } catch (e) {
                        console.error(`❌ ERRO NO SORTEIO ${idSorteio} (Tentativa ${tentativa}):`, e.message);

                        // DEU ERRO! MATA TUDO PARA LIMPAR A MEMÓRIA
                        if (browser) await browser.close().catch(() => { });
                        if (ffmpeg) ffmpeg.kill('SIGKILL');

                        if (tentativa === 1) {
                            console.log(`🔄 Iniciando Tentativa 2... Avisando o grupo.`);
                            try {
                                await axios.post('http://localhost:3000/api/aviso-retry', { idSorteio });
                            } catch (err) { }

                            // PAUSA DE 5 SEGUNDOS PARA O SERVIDOR RESPIRAR ANTES DE TENTAR DE NOVO
                            await new Promise(r => setTimeout(r, 5000));
                        }
                    }
                }

                // SE MESMO NA TENTATIVA 2 DEU ERRO, AI SIM VAI PARA MANUAL
                if (!sucesso) {
                    console.log(`⛔ [SORTEIO ${idSorteio}] Falhou nas 2 tentativas. Indo para manual.`);
                    await pool.execute("UPDATE sorteios SET status = 'aguardando_manual' WHERE id = ?", [idSorteio]);
                }

                gravadorOcupado = false;
            }
        } catch (e) {
            console.error("Erro geral no cinegrafista:", e.message);
            gravadorOcupado = false;
        }
    }, 5000);
}

iniciarGravador();