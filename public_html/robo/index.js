require('dotenv').config();
const qrcode = require('qrcode-terminal');
const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion
} = require('@whiskeysockets/baileys');
const { Boom } = require('@hapi/boom');
const pino = require('pino');
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const express = require('express');

const BASE_PATH = __dirname + '/';

const GRUPOS = {
    '120363406517236188@g.us': 'testes',
    '120363159570576952@g.us': 'carnes',
    '120363207504856249@g.us': 'bebidas'
};

const pool = mysql.createPool({
    host: process.env.DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASS,
    database: process.env.DB_NAME,
    charset: 'utf8mb4',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
});

const MERCADOPAGO_ACCESS_TOKEN = process.env.MP_ACCESS_TOKEN;
const MERCADOPAGO_API_URL = 'https://api.mercadopago.com/v1/payments';

global.sock = null;

let ultimoStatusPorSorteio = {};
let sorteiosNotificados = new Set();
let timeoutsAtualizacao = new Map();
let processandoFinalizacao = new Set();

const app = express();
app.use(express.json());
const WEBHOOK_PORT = 3000;

function formatarPremios(string) {
    if (!string) return "Sem prêmios";
    const numeros = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
    return string.split("|||").map((p, i) => `${numeros[i] || (i + 1)} ${p}`).join('\n');
}

function agendarAtualizacaoLista(rifa, groupId) {
    const id = rifa.id;
    if (timeoutsAtualizacao.has(id)) {
        console.log(`⏳ Alteração detectada no Sorteio ${id}, mas já existe atualização agendada. Agrupando...`);
        return;
    }
    console.log(`⏰ Iniciando timer de 1 MINUTO para atualizar lista do Sorteio ${id}...`);
    const timer = setTimeout(async () => {
        try {
            console.log(`📤 Timer de 1 min acabou! Enviando lista acumulada do Sorteio ${id}.`);
            await enviarListaAtualizada(rifa, groupId);
        } catch (e) {
            console.error(`Erro ao enviar lista agendada:`, e);
        } finally {
            timeoutsAtualizacao.delete(id);
        }
    }, 60000);

    timeoutsAtualizacao.set(id, timer);
}

async function forcarAtualizacaoImediata(rifa, groupId) {
    const id = rifa.id;
    console.log(`🚀 FORÇANDO ATUALIZAÇÃO IMEDIATA (Sorteio ${id})...`);

    if (timeoutsAtualizacao.has(id)) {
        clearTimeout(timeoutsAtualizacao.get(id));
        timeoutsAtualizacao.delete(id);
        console.log(`❌ Timer anterior cancelado.`);
    }

    try {
        const [v] = await pool.execute("SELECT COUNT(*) as t FROM vendas WHERE sorteio_id = ?", [id]);
        const [p] = await pool.execute("SELECT COUNT(*) as t FROM vendas WHERE sorteio_id = ? AND status_venda = 'pago'", [id]);

        ultimoStatusPorSorteio[id] = `${v[0].t}_${p[0].t}`;
    } catch (e) {
        console.error("Erro ao sincronizar status global:", e.message);
    }

    await enviarListaAtualizada(rifa, groupId);
}

async function enviarListaAtualizada(rifa, groupId) {
    if (!global.sock || !global.sock.user) return;
    try {
        // 🔥 TRAVA ANTI-FANTASMA: Verifica se o sorteio ainda está rodando de verdade
        const [checkSorteio] = await pool.execute("SELECT status FROM sorteios WHERE id = ?", [rifa.id]);
        if (checkSorteio.length > 0 && checkSorteio[0].status !== 'ativo') {
            console.log(`🚫 Sorteio ${rifa.id} já está '${checkSorteio[0].status}'. Lista atrasada cancelada para não poluir o grupo.`);
            return;
        }

        const [vendas] = await pool.execute("SELECT numero_escolhido, status_venda, nome_comprador FROM vendas WHERE sorteio_id = ? ORDER BY numero_escolhido", [rifa.id]);
        let mapa = {};
        vendas.forEach(v => mapa[v.numero_escolhido] = v);
        const total = rifa.qtd_numeros || 25;
        const totalPagos = vendas.filter(v => v.status_venda === 'pago').length;
        const totalVendas = vendas.length;
        const numVisual = String(rifa.numero_visual || rifa.id).padStart(2, '0');

        let cabecalhos = [
            `*${process.env.NOME_CLIENTE || 'SORTEIOS'}*\n 📢 *Sorteios Promocionais Diários*\n\n`,
            `*${process.env.NOME_CLIENTE || 'SORTEIOS'}*\n 🏆 *Participe e concorra!*\n\n`,
            `*${process.env.NOME_CLIENTE || 'SORTEIOS'}*\n ⭐ *Sua chance de ganhar!*\n\n`
        ];

        const cabecalhoSorteado = cabecalhos[Math.floor(Math.random() * cabecalhos.length)];

        let msg = `${cabecalhoSorteado}` +
            `${rifa.categoria === 'carnes' ? '🥩' : rifa.categoria === 'bebidas' ? '🍺' : '🧪'} *SORTEIO:* ${rifa.titulo} #${numVisual}\n` +
            `💰 *VALOR:* R$ ${rifa.valor_numero.replace('.', ',')}\n\n` +
            `📜 *PRÊMIOS:*\n${formatarPremios(rifa.premios)}\n\n`;

        if (totalVendas < total) msg += `🚀 *ABERTO! ESCREVA #NUMERO*\n\n`;
        msg += `📊 *LISTA:*`;

        let listaTxt = "";
        for (let i = 1; i <= total; i++) {
            let n = i.toString().padStart(2, '0');
            if (mapa[i]) {
                let st = mapa[i].status_venda === 'pago' ? '✅' : '⏳';
                listaTxt += `\n${n} - ${mapa[i].nome_comprador} ${st}`;
            } else {
                listaTxt += `\n${n}`;
            }
        }
        msg += listaTxt;

        if (totalPagos >= total) {
            msg += `\n\n✅ *SORTEIO COMPLETO!*\n⏰ *Aguarde, vamos sortear em instantes...*`;
        } else if (totalVendas >= total) {
            msg += `\n\n🚫 *RIFA FECHADA!*\n⏳ *Aguardando pagamentos...*`;
        }

        await global.sock.sendMessage(groupId, { text: msg });
    } catch (e) {
        console.error("Erro ao enviar lista:", e.message);
    }
}

async function enviarMidiasIniciais(rifa, groupId) {
    if (!global.sock || !global.sock.user) return;

    const dirUploads = path.join(__dirname, '../assets/uploads');

    try {
        // 1. Manda a FOTO 1
        if (rifa.imagem) {
            const img1 = path.join(dirUploads, rifa.imagem);
            if (fs.existsSync(img1)) {
                await global.sock.sendMessage(groupId, { image: fs.readFileSync(img1) });
                await new Promise(r => setTimeout(r, 3000));
            }
        }

        // 2. Manda a FOTO 2 (Se existir)
        if (rifa.imagem2) {
            const img2 = path.join(dirUploads, rifa.imagem2);
            if (fs.existsSync(img2)) {
                await global.sock.sendMessage(groupId, { image: fs.readFileSync(img2) });
                await new Promise(r => setTimeout(r, 3000));
            }
        }

        // 3. Manda o VÍDEO
        if (rifa.video) {
            const vid = path.join(dirUploads, rifa.video);
            if (fs.existsSync(vid)) {
                await global.sock.sendMessage(groupId, { video: fs.readFileSync(vid) });
                await new Promise(r => setTimeout(r, 4500));
            }
        }
    } catch (e) {
        console.error("Erro ao enviar as midias iniciais:", e);
    }
}

// ==========================================
// FUNÇÕES AGENDA + CARTEIRA
// ==========================================
async function processarPagamentoUnificado(clienteId, valorTotal, sorteioId, descricao) {
    const conn = await pool.getConnection();
    try {
        await conn.beginTransaction();
        const [cart] = await conn.execute("SELECT saldo, credito_limite, credito_usado, status FROM carteiras WHERE cliente_id = ? FOR UPDATE", [clienteId]);

        if (!cart || cart.length === 0) {
            await conn.rollback();
            return { success: false, erro: 'Cliente não possui carteira' };
        }

        if (cart[0].status === 'bloqueado') {
            await conn.rollback();
            return { success: false, erro: 'Carteira bloqueada' };
        }

        let saldo = parseFloat(cart[0].saldo || 0);
        let limite = parseFloat(cart[0].credito_limite || 0);
        let usado = parseFloat(cart[0].credito_usado || 0);
        let disponivelCredito = limite - usado;

        let doSaldo = 0;
        let doCredito = 0;

        if (saldo >= valorTotal) {
            doSaldo = valorTotal;
        } else {
            doSaldo = saldo;
            doCredito = valorTotal - saldo;
            if (doCredito > disponivelCredito) {
                await conn.rollback();
                return { success: false, erro: 'Limite Insuficiente', saldoAtual: saldo };
            }
        }

        await conn.execute("UPDATE carteiras SET saldo = saldo - ?, credito_usado = credito_usado + ? WHERE cliente_id = ?", [doSaldo, doCredito, clienteId]);

        const [t] = await conn.execute(
            "INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, sorteio_id) VALUES (?, 'compra_saldo', ?, ?, ?)",
            [clienteId, valorTotal, descricao, sorteioId]
        );

        await conn.commit();

        return { success: true, transacaoId: t.insertId, pagoComSaldo: doSaldo, pagoComCredito: doCredito };
    } catch (e) {
        await conn.rollback();
        console.error("Erro Crítico Pagamento:", e);
        return { success: false, erro: e.message };
    } finally {
        conn.release();
    }
}

async function buscarClienteNaAgenda(idWhatsapp) {
    try {
        const id = String(idWhatsapp).split('@')[0].replace(/\D/g, '');
        const [result] = await pool.execute("SELECT * FROM agenda_clientes WHERE id_whatsapp = ?", [id]);
        return result[0] || null;
    } catch (error) {
        console.error('Erro ao buscar cliente:', error);
        return null;
    }
}

async function criarClienteNaAgenda(idWhatsapp, telefone, nomeWhatsApp) {
    // Verificar se nome já existe
    const [existente] = await pool.execute(

        'SELECT nome_fixo FROM agenda_clientes WHERE nome_fixo = ?',
        [nomeWhatsApp]
    );

    let nomeFinal = nomeWhatsApp;

    if (existente.length > 0) {
        // Nome duplicado! Adicionar final do telefone
        const ultimos4 = telefone.slice(-4);
        nomeFinal = `${nomeWhatsApp} (${ultimos4})`;
        console.log(`⚠️ Nome duplicado! Salvando como: ${nomeFinal}`);
    }

    await pool.execute(
        `INSERT INTO agenda_clientes (id_whatsapp, telefone, nome_fixo, nome_whatsapp, total_jogadas) 
         VALUES (?, ?, ?, ?, 0)`,
        [idWhatsapp, telefone, nomeFinal, nomeWhatsApp]
    );

    console.log(`📒 Cliente criado: ${nomeFinal} | ID: ${idWhatsapp}`);
    return nomeFinal;
}
async function atualizarNomeWhatsApp(idWhatsapp, nomeWhatsApp) {
    try {
        const id = String(idWhatsapp).split('@')[0].replace(/\D/g, '');
        await pool.execute(
            "UPDATE agenda_clientes SET nome_whatsapp = ? WHERE id_whatsapp = ?",
            [nomeWhatsApp, id]
        );
    } catch (error) {
        console.error('Erro atualizar nome WhatsApp:', error);
    }
}

async function verificarCarteira(telefone) {
    try {
        const id = String(telefone).split('@')[0].replace(/\D/g, '');
        const [result] = await pool.execute(`
            SELECT c.*, a.nome_fixo 
            FROM carteiras c
            JOIN agenda_clientes a ON c.cliente_id = a.id
        WHERE a.id_whatsapp = ? AND c.status = 'ativo'
          `, [id]);
        return result[0] || null;
    } catch (error) {
        return null;
    }
}

async function descontarSaldo(clienteId, valor, sorteioId, descricao) {
    const conn = await pool.getConnection();
    try {
        await conn.beginTransaction();
        const [carteira] = await conn.execute("SELECT saldo FROM carteiras WHERE cliente_id = ? FOR UPDATE", [clienteId]);
        if (!carteira[0] || carteira[0].saldo < valor) {
            await conn.rollback();
            return { success: false };
        }
        const saldoNovo = carteira[0].saldo - valor;
        await conn.execute("UPDATE carteiras SET saldo = ? WHERE cliente_id = ?", [saldoNovo, clienteId]);
        const [t] = await conn.execute(
            "INSERT INTO transacoes_carteira (cliente_id, tipo, valor, saldo_anterior, saldo_novo, descricao, sorteio_id) VALUES (?, 'compra_saldo', ?, ?, ?, ?, ?)",
            [clienteId, valor, carteira[0].saldo, saldoNovo, descricao, sorteioId]
        );
        await conn.commit();
        return { success: true, saldoNovo, transacaoId: t.insertId };
    } catch (error) {
        await conn.rollback();
        return { success: false };
    } finally {
        conn.release();
    }
}

async function descontarCredito(clienteId, valor, sorteioId, descricao) {
    const conn = await pool.getConnection();
    try {
        await conn.beginTransaction();
        const [carteira] = await conn.execute(
            "SELECT credito_limite, credito_usado FROM carteiras WHERE cliente_id = ? FOR UPDATE",
            [clienteId]
        );
        if (!carteira[0]) {
            await conn.rollback();
            return { success: false };
        }
        const disponivel = carteira[0].credito_limite - carteira[0].credito_usado;
        if (disponivel < valor) {
            await conn.rollback();
            return { success: false };
        }
        const creditoNovo = carteira[0].credito_usado + valor;
        await conn.execute("UPDATE carteiras SET credito_usado = ? WHERE cliente_id = ?", [creditoNovo, clienteId]);
        const [t] = await conn.execute(
            "INSERT INTO transacoes_carteira (cliente_id, tipo, valor, credito_anterior, credito_novo, descricao, sorteio_id) VALUES (?, 'compra_credito', ?, ?, ?, ?, ?)",
            [clienteId, valor, carteira[0].credito_usado, creditoNovo, descricao, sorteioId]
        );
        await conn.commit();
        return {
            success: true,
            creditoNovo,
            limite: carteira[0].credito_limite,
            transacaoId: t.insertId
        };
    } catch (error) {
        await conn.rollback();
        return { success: false };
    } finally {
        conn.release();
    }
}

// FIM FUNÇÕES CARTEIRA

app.post('/api/enviar-resultado-manual', async (req, res) => {
    try {
        const { sorteio_id, categoria, ganhadores, titulo } = req.body;
        if (!sorteio_id || !ganhadores || !global.sock) {
            return res.status(400).json({ error: 'Dados inválidos ou robô offline' });
        }
        const groupId = Object.keys(GRUPOS).find(k => GRUPOS[k] === categoria);
        if (!groupId) {
            return res.status(400).json({ error: 'Grupo não encontrado' });
        }

        const emojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
        let msgRes = `🏆 *RESULTADO OFICIAL - SORTEIO MANUAL*\n\n🎰 *${titulo}*\n\n`;
        ganhadores.forEach((g, i) => {
            msgRes += `${emojis[i] || `${i + 1}º`} *${g.premio}*\n👤 ${g.nome} — *Nº ${g.numero}*\n\n`;
        });
        msgRes += `_Retire seu prêmio em 48h no ${process.env.NOME_CLIENTE}_`;
        await global.sock.sendMessage(groupId, { text: msgRes });

        for (const [i, g] of ganhadores.entries()) {
            if (g.telefone) {
                try {
                    let userJid = g.telefone.toString().replace(/\D/g, '');
                    let isLid = userJid.length >= 14;

                    if (isLid) {
                        userJid = `${userJid}@lid`;
                    } else {
                        userJid = `${userJid}@s.whatsapp.net`;
                        const [waUser] = await global.sock.onWhatsApp(userJid);
                        if (waUser && waUser.exists) {
                            userJid = waUser.jid;
                        }
                    }
                    const emojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];

                    // Pegando data e hora atual
                    const agora = new Date();
                    const dataFormatada = agora.toLocaleDateString('pt-BR');
                    const horaFormatada = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

                    const msgPrivada = `🎉 *PARABÉNS! VOCÊ GANHOU!*\n\n🎰 *Sorteio:* ${titulo}\n🏅 *Colocação:* ${emojis[i] || `${i + 1}º`} Lugar\n🎁 *Prêmio:* ${g.premio}\n🎟️ *Número:* ${g.numero}\n📅 *Data:* ${dataFormatada} às ${horaFormatada}\n\n⚠️ *Você tem 48hrs para retirar no ${process.env.NOME_CLIENTE}!*`;
                    await global.sock.sendMessage(userJid, { text: msgPrivada });
                    await new Promise(r => setTimeout(r, 4500));
                } catch (e) {
                    console.error(`❌ Erro ao enviar para ${g.nome}:`, e.message);
                }
            }
        }
        res.json({ success: true, message: 'Mensagens enviadas!' });
    } catch (e) {
        console.error("Erro na API enviar-resultado:", e);
        res.status(500).json({ error: e.message });
    }
});

app.post('/api/chamar-todos', async (req, res) => {
    try {
        const { categoria } = req.body;
        const catNormalizada = categoria ? categoria.toLowerCase() : '';
        const groupId = Object.keys(GRUPOS).find(key => GRUPOS[key] === catNormalizada);
        if (!groupId || !global.sock) return res.status(400).json({ error: 'Grupo ou Robô offline' });
        res.status(200).json({ success: true });
        setImmediate(async () => {
            try {
                const metadata = await global.sock.groupMetadata(groupId);
                const participants = metadata.participants.map(p => p.id);
                const text = "🚨 *ATENÇÃO: SORTEIO ROLANDO!* 🚨\n\n🏃‍♂️ *Corra para garantir seus números!*";
                await global.sock.sendMessage(groupId, { text: text, mentions: participants });
            } catch (innerErr) { }
        });
    } catch (e) {
        res.status(500).json({ error: e.message });
    }
});

app.post('/webhook/mercadopago', async (req, res) => {
    try {
        const notification = req.body;
        if ((notification.type === 'payment' || notification.action === 'payment.updated') && global.sock) {
            const paymentId = notification.data.id;
            const paymentResponse = await axios.get(`${MERCADOPAGO_API_URL}/${paymentId}`, {
                headers: { 'Authorization': `Bearer ${MERCADOPAGO_ACCESS_TOKEN}` }
            });
            const payment = paymentResponse.data;

            if (payment.status === 'approved') {

                // ==========================================
                // 1. VERIFICA SE O PAGAMENTO É DE UMA RECARGA OU FATURA
                // ==========================================
                const [recargas] = await pool.execute("SELECT * FROM pagamentos_pix WHERE payment_id = ? AND status = 'pending'", [paymentId]);

                if (recargas.length > 0) {
                    const recarga = recargas[0];
                    const clienteId = recarga.cliente_id;
                    const valor = parseFloat(recarga.valor);

                    await pool.execute("UPDATE pagamentos_pix SET status = 'approved' WHERE id = ?", [recarga.id]);

                    if (recarga.tipo === 'fatura_mensal') {
                        // 1. ZERA A DÍVIDA DO CLIENTE!
                        await pool.execute("UPDATE carteiras SET credito_usado = 0 WHERE cliente_id = ?", [clienteId]);

                        // 2. BUSCA O LIMITE PARA MOSTRAR NA MENSAGEM
                        const [cartDados] = await pool.execute("SELECT credito_limite FROM carteiras WHERE cliente_id = ?", [clienteId]);
                        const limiteDisponivel = cartDados[0] ? parseFloat(cartDados[0].credito_limite) : 0;

                        await pool.execute(
                            "INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES (?, 'ajuste_admin', ?, 'Pagamento da Fatura Mensal (PIX Automático)', NOW())",
                            [clienteId, valor]
                        );

                        const [clienteData] = await pool.execute("SELECT id_whatsapp FROM agenda_clientes WHERE id = ?", [clienteId]);
                        if (clienteData.length > 0) {
                            let jidEnvio = clienteData[0].id_whatsapp;
                            jidEnvio = jidEnvio.length >= 14 ? `${jidEnvio}@lid` : `${jidEnvio}@s.whatsapp.net`;
                            await global.sock.sendMessage(jidEnvio, {
                                text: `✅ *FATURA PAGA COM SUCESSO!*\n\n💰 Valor Pago: R$ ${valor.toFixed(2).replace('.', ',')}\n💳 Seu limite disponível agora é: *R$ ${limiteDisponivel.toFixed(2).replace('.', ',')}*\n\nO seu crédito mensal na *${process.env.NOME_CLIENTE}* foi reestabelecido! 🍀`
                            });
                        }
                        return res.status(200).send('OK');
                    } else {
                        // RECARGA DE SALDO NORMAL
                        const [carteiras] = await pool.execute("SELECT * FROM carteiras WHERE cliente_id = ?", [clienteId]);

                        let saldoNovo = valor;
                        if (carteiras.length === 0) {
                            await pool.execute("INSERT INTO carteiras (cliente_id, saldo, credito_limite, credito_usado, status) VALUES (?, ?, 0, 0, 'ativo')", [clienteId, valor]);
                        } else {
                            saldoNovo = parseFloat(carteiras[0].saldo) + valor;
                            await pool.execute("UPDATE carteiras SET saldo = ? WHERE cliente_id = ?", [saldoNovo, clienteId]);
                        }

                        await pool.execute("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, saldo_anterior, saldo_novo, descricao) VALUES (?, 'recarga_pix', ?, ?, ?, 'Recarga Pix via WhatsApp')", [clienteId, valor, carteiras.length > 0 ? carteiras[0].saldo : 0, saldoNovo]);

                        const [clienteData] = await pool.execute("SELECT id_whatsapp, nome_fixo FROM agenda_clientes WHERE id = ?", [clienteId]);
                        if (clienteData.length > 0) {
                            let jidEnvio = clienteData[0].id_whatsapp;
                            jidEnvio = jidEnvio.length >= 14 ? `${jidEnvio}@lid` : `${jidEnvio}@s.whatsapp.net`;
                            await global.sock.sendMessage(jidEnvio, {
                                text: `✅ *RECARGA APROVADA!*\n\n💰 Valor: R$ ${valor.toFixed(2).replace('.', ',')}\n💵 Seu saldo atual é: *R$ ${saldoNovo.toFixed(2).replace('.', ',')}*\n\nVocê já pode comprar seus números direto pelo saldo!`
                            });
                        }
                        return res.status(200).send('OK');
                    }
                }

                // ==========================================
                // 2. SE NÃO FOR RECARGA, É COMPRA DE Sorteio NORMAL
                // ==========================================
                const telefone = payment.metadata?.telefone;
                const numerosStr = payment.metadata?.numeros;

                if (telefone && numerosStr) {
                    const numeros = numerosStr.split(',').map(n => parseInt(n));
                    try {
                        for (const numero of numeros) {
                            await pool.execute("UPDATE vendas SET status_venda = 'pago', data_reserva = NOW() WHERE telefone = ? AND numero_escolhido = ?", [telefone, numero]);
                        }
                        const [vendas] = await pool.execute("SELECT DISTINCT sorteio_id FROM vendas WHERE telefone = ? AND numero_escolhido IN (?)", [telefone, numeros]);
                        if (vendas.length > 0) {
                            const sid = vendas[0].sorteio_id;
                            const [rifas] = await pool.execute("SELECT * FROM sorteios WHERE id = ?", [sid]);
                            if (rifas.length > 0) {
                                const rifa = rifas[0];
                                let jidEnvio = telefone.toString().replace(/\D/g, '');
                                jidEnvio = jidEnvio.length >= 14 ? `${jidEnvio}@lid` : `${jidEnvio}@s.whatsapp.net`;

                                const saudacoes = ['Oba!', 'Maravilha!', 'Sucesso!', 'Show!', 'Tudo certo!'];
                                const saudacao = saudacoes[Math.floor(Math.random() * saudacoes.length)];
                                const protocolo = Math.floor(Math.random() * 90000) + 10000;
                                const delayConf = Math.floor(Math.random() * 3000) + 2000;
                                await new Promise(r => setTimeout(r, delayConf));

                                await global.sock.sendMessage(jidEnvio, {
                                    text: `✅ ${saudacao} Pagamento confirmado.\n\n🎁 Rifa: ${rifa.titulo}\n🎫 Números: ${numeros.join(', ')}\n🍀 Boa sorte!\n\n_Recibo: ${protocolo}_`
                                });
                            }
                        }
                    } catch (e) {
                        console.error(e);
                    }
                }
            }
        }
        res.status(200).send('OK');
    } catch (e) {
        console.error("Erro no Webhook MP:", e);
        res.status(500).send('Erro');
    }
});

app.post('/api/enviar-cobranca-credito', async (req, res) => {
    try {
        const { telefone, nome, valor, cliente_id } = req.body;
        if (!telefone || !global.sock) {
            return res.status(400).json({ error: 'Dados inválidos ou robô offline' });
        }

        let userJid = telefone.toString().replace(/\D/g, '');
        userJid = userJid.length >= 14 ? `${userJid}@lid` : `${userJid}@s.whatsapp.net`;

        const valorFloat = parseFloat(valor);
        const desc = `Pagamento Fatura Mensal - ${nome}`;

        const pagMP = await criarPagamentoMercadoPago(valorFloat.toFixed(2), desc, userJid, [0]);

        const pixCode = pagMP.point_of_interaction?.transaction_data?.qr_code;
        const pixId = pagMP.id;
        await pool.execute(
            `INSERT INTO pagamentos_pix (payment_id, cliente_id, valor, status, tipo, qr_code, data_criacao) 
             VALUES (?, ?, ?, 'pending', 'fatura_mensal', ?, NOW())`,
            [pixId, cliente_id, valorFloat, pixCode || '']
        );

        const msg = `💳 *COBRANÇA DE CRÉDITO MENSAL*\n\nOlá ${nome}!\n\nA sua fatura de crédito da *${process.env.NOME_CLIENTE}* foi fechada.\n💰 *Total a pagar:* R$ ${valorFloat.toFixed(2).replace('.', ',')}\n\n📋 *Pague agora via PIX Copia e Cola abaixo:*`; await global.sock.sendMessage(userJid, { text: msg });

        if (pixCode) {
            await global.sock.sendMessage(userJid, { text: pixCode });
        }

        res.json({ success: true });
    } catch (e) {
        console.error("Erro ao enviar cobrança:", e);
        res.status(500).json({ error: e.message });
    }
});
app.post('/api/enviar-mensagem-simples', async (req, res) => {
    try {
        const { telefone, mensagem } = req.body;
        if (!telefone || !global.sock) return res.status(400).json({ error: 'Erro' });

        let userJid = telefone;
        if (!userJid.includes('@s.whatsapp.net')) {
            userJid = `${userJid.replace(/\D/g, '')}@s.whatsapp.net`;
        }

        await global.sock.sendMessage(userJid, { text: mensagem });
        res.json({ success: true });
    } catch (e) {
        console.error("Erro ao enviar mensagem simples:", e);
        res.status(500).json({ error: e.message });
    }
});

// BOTÃO 1: Reenviar a Lista Completa
app.post('/api/aviso-retry', async (req, res) => {
    try {
        const { idSorteio } = req.body;
        if (!global.sock) return res.status(400).send('Offline');

        const [rifas] = await pool.execute("SELECT categoria FROM sorteios WHERE id = ?", [idSorteio]);
        if (rifas.length > 0) {
            const cat = rifas[0].categoria;
            const groupId = Object.keys(GRUPOS).find(k => GRUPOS[k] === cat);

            if (groupId) {
                await global.sock.sendMessage(groupId, {
                    text: "⚠️ *Estamos com instabilidade no sistema, tentando novamente fazer o sorteio...*\n\n🎰 *O globo está girando, boa sorte!*"
                });
            }
        }
        res.json({ success: true });
    } catch (e) {
        console.error("Erro no aviso de retry:", e);
        res.status(500).send('Erro');
    }
});
app.post('/api/reenviar-lista', async (req, res) => {
    try {
        const { sorteio_id, categoria } = req.body;
        if (!sorteio_id || !global.sock) return res.status(400).json({ error: 'Erro de dados ou robô offline' });

        const catNormalizada = categoria ? categoria.toLowerCase() : '';
        const groupId = Object.keys(GRUPOS).find(key => GRUPOS[key] === catNormalizada);

        if (!groupId) return res.status(400).json({ error: 'Grupo não encontrado' });

        const [rifas] = await pool.execute("SELECT * FROM sorteios WHERE id = ?", [sorteio_id]);
        if (rifas.length > 0) {
            await forcarAtualizacaoImediata(rifas[0], groupId);
            res.json({ success: true, message: 'Lista reenviada com sucesso!' });
        } else {
            res.status(404).json({ error: 'Sorteio não encontrado' });
        }
    } catch (e) {
        console.error("Erro ao reenviar lista:", e);
        res.status(500).json({ error: e.message });
    }
});

// BOTÃO 2: Enviar alerta de números restantes
app.post('/api/enviar-alerta-restantes', async (req, res) => {
    try {
        const { sorteio_id, categoria } = req.body;
        if (!sorteio_id || !global.sock) return res.status(400).json({ error: 'Erro de dados ou robô offline' });

        const catNormalizada = categoria ? categoria.toLowerCase() : '';
        const groupId = Object.keys(GRUPOS).find(key => GRUPOS[key] === catNormalizada);

        if (!groupId) return res.status(400).json({ error: 'Grupo não encontrado' });

        // Puxa os dados da rifa
        const [rifas] = await pool.execute("SELECT * FROM sorteios WHERE id = ?", [sorteio_id]);
        if (rifas.length === 0) return res.status(404).json({ error: 'Sorteio não encontrado' });

        const rifa = rifas[0];
        const total = rifa.qtd_numeros || 25;
        const numVisual = String(rifa.numero_visual || rifa.id).padStart(2, '0');

        // Puxa quais números já foram vendidos/reservados
        const [vendas] = await pool.execute("SELECT numero_escolhido FROM vendas WHERE sorteio_id = ?", [sorteio_id]);
        const ocupados = new Set(vendas.map(v => v.numero_escolhido));

        // Descobre os que faltam
        let faltam = [];
        for (let i = 1; i <= total; i++) {
            if (!ocupados.has(i)) {
                faltam.push(i);
            }
        }

        if (faltam.length === 0) {
            return res.json({ success: false, message: 'O sorteio já está cheio!' });
        }

        // Monta a frase inteligente dependendo da quantidade
        const qtdFaltam = faltam.length;
        const palavraFalta = qtdFaltam === 1 ? 'Falta apenas' : 'Faltam apenas';
        const palavraNumero = qtdFaltam === 1 ? 'número' : 'números';

        const mensagem = `🚨 *ATENÇÃO: QUASE FECHANDO!* 🚨\n\n🎰 *${rifa.titulo} #${numVisual}*\n\n🔥 ${palavraFalta} *${qtdFaltam} ${palavraNumero}* para finalizar o sorteio!\n\n👉 *Restantes:* *${faltam.join(', ')}*\n\n🏃‍♂️ Envie *#numero* ou *#fechar* para garantir!`;

        await global.sock.sendMessage(groupId, { text: mensagem });
        res.json({ success: true, message: 'Alerta enviado com sucesso!' });

    } catch (e) {
        console.error("Erro ao enviar alerta de restantes:", e);
        res.status(500).json({ error: e.message });
    }
});
async function connectToWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState(path.join(BASE_PATH, 'baileys_auth'));
    const { version } = await fetchLatestBaileysVersion();
    const sock = makeWASocket({
        version,
        auth: state,
        logger: pino({ level: 'silent' }),
        browser: ["Ubuntu", "Chrome", "20.0.04"],
        connectTimeoutMs: 60000
    });
    global.sock = sock;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        // --- TRAVA DE MANUTENÇÃO (03:00 às 03:10) ---
        const agora = new Date();
        const isManutencao = (agora.getHours() === 3 && agora.getMinutes() <= 10);

        if (qr) {
            console.log('\n📱 NOVO QR CODE GERADO:');
            qrcode.generate(qr, { small: true });
        }

        if (connection === 'close') {
            // Captura o código de erro de forma robusta
            const statusCode = (lastDisconnect?.error?.output?.statusCode) || 0;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            console.log(`⚠️ CONEXÃO FECHADA | Motivo: ${statusCode}`);

            // 🛑 AVISA NO TELEGRAM SE NÃO FOR MANUTENÇÃO
            if (!isManutencao) {
                const telegramToken = '8641151787:AAF6xmvEjs5E7XpUy5OpRlQKIjwnf8wqgu8';
                const telegramChatId = '8720947602';
                const mensagem = `⚠️ *ALERTA D'KING:* O Robô do ${process.env.NOME_CLIENTE} está *OFF-LINE*!\n\n🔴 Status: ${statusCode}\n🔄 Tentando reconectar: ${shouldReconnect ? 'SIM' : 'NÃO'}\n\n_Verifique a conexão ou o servidor se o sistema não voltar em 2 minutos._`;

                await axios.post(`https://api.telegram.org/bot${telegramToken}/sendMessage`, {
                    chat_id: telegramChatId,
                    text: mensagem,
                    parse_mode: 'Markdown'
                }).catch(err => console.log("Erro ao avisar Telegram na queda"));
            }

            if (shouldReconnect) connectToWhatsApp();

        } else if (connection === 'open') {
            console.log('✅ ROBÔ ONLINE');

            // 🚀 AVISA QUE VOLTOU (SÓ FORA DA MANUTENÇÃO)
            if (!isManutencao) {
                const telegramToken = '8641151787:AAF6xmvEjs5E7XpUy5OpRlQKIjwnf8wqgu8';
                const telegramChatId = '8720947602';
                axios.post(`https://api.telegram.org/bot${telegramToken}/sendMessage`, {
                    chat_id: telegramChatId,
                    text: "🚀 *D'KING:* O Robô está *ONLINE* e operando novamente!",
                    parse_mode: 'Markdown'
                }).catch(err => console.log("Erro ao avisar Telegram na volta"));
            }
        }
    });
    sock.ev.on('messages.upsert', async m => {
        const msg = m.messages[0];
        if (!msg.message || msg.key.fromMe) return;

        const groupJid = msg.key.remoteJid;
        const participant = msg.key.participant || msg.key.remoteJid;
        const text = msg.message.conversation || msg.message.extendedTextMessage?.text || "";
        if (!text) return;

        const body = text.trim().toLowerCase();
        const category = GRUPOS[groupJid];

        // DEBUG
        if (groupJid.endsWith('@g.us')) {
            console.log('🔍 GRUPO:', groupJid);
            console.log('   Categoria:', category || 'NÃO CONFIGURADO');
            console.log('   Mensagem:', text.substring(0, 50));
            console.log('---');
        }
        // ==========================================
        // COMANDOS DE CARTEIRA
        // ==========================================

        // COMANDO: #SALDO (Atualizado com Saldo + Crédito Mensal)
        if (!groupJid.endsWith('@g.us') && body.match(/^#saldo$/i)) {
            try {
                const idWhatsapp = participant.split('@')[0].replace(/\D/g, '');
                const cliente = await buscarClienteNaAgenda(idWhatsapp);

                if (!cliente) {
                    await sock.sendMessage(participant, {
                        text: '❌ Você ainda não está cadastrado!\n\nFaça uma reserva primeiro (#1) para criar sua carteira.'
                    });
                    return;
                }

                const carteira = await verificarCarteira(idWhatsapp);

                if (!carteira) {
                    await sock.sendMessage(participant, {
                        text: '❌ Você não possui carteira ativa!\n\nEntre em contato com o administrador.'
                    });
                    return;
                }

                // Cálculo dos valores
                const saldoDisp = parseFloat(carteira.saldo || 0);
                const limiteTotal = parseFloat(carteira.credito_limite || 0);
                const creditoUsado = parseFloat(carteira.credito_usado || 0);
                const creditoDisponivel = limiteTotal - creditoUsado;

                // NOVA SOMA: Total disponível para jogar
                const poderCompraTotal = saldoDisp + creditoDisponivel;

                // Montagem da Mensagem Personalizada
                let mensagem = `💰 *SUA CARTEIRA - ${process.env.NOME_CLIENTE}*\n\n`;
                mensagem += `👤 Nome: *${cliente.nome_fixo}*\n`;
                mensagem += `━━━━━━━━━━━━━━━\n\n`;

                mensagem += `🍀 *DISPONÍVEL PARA JOGAR:* R$ ${poderCompraTotal.toFixed(2).replace('.', ',')}\n\n`;
                mensagem += `💵 *SALDO EM CONTA:* R$ ${saldoDisp.toFixed(2).replace('.', ',')}\n`;

                // Só exibe informações de crédito se o cliente tiver um limite maior que zero
                if (limiteTotal > 0) {
                    mensagem += `💳 *LIMITE MENSAL:* R$ ${limiteTotal.toFixed(2).replace('.', ',')}\n`;
                    mensagem += `✅ *CRÉDITO DISPONÍVEL:* R$ ${creditoDisponivel.toFixed(2).replace('.', ',')}\n`;

                    if (creditoUsado > 0) {
                        mensagem += `⚠️ *USADO NO MÊS:* R$ ${creditoUsado.toFixed(2).replace('.', ',')}\n`;
                    }
                }

                mensagem += `\n━━━━━━━━━━━━━━━\n📊 Use *#historico* para ver suas transações`;

                await sock.sendMessage(participant, { text: mensagem });
                return;
            } catch (error) {
                console.error('❌ Erro ao consultar saldo:', error);
            }
        }
        // COMANDO: #HISTORICO (Mostra Compras + Recargas)
        let comandoLimpo = body.normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();

        if (!groupJid.endsWith('@g.us') && comandoLimpo === '#historico') {
            try {
                const idWhatsapp = participant.split('@')[0].replace(/\D/g, '');
                const cliente = await buscarClienteNaAgenda(idWhatsapp);

                if (!cliente) {
                    await sock.sendMessage(participant, { text: '❌ Você ainda não está cadastrado!\nFaça uma jogada primeiro para criar seu perfil.' });
                    return;
                }

                const historico = [];

                // 1. Busca Compras Reais (Vendas Pagas) e puxa o numero visual do sorteio
                const [vendas] = await pool.execute(`
                    SELECT v.sorteio_id, s.titulo, s.numero_visual, s.valor_numero, v.forma_pagamento, MAX(v.data_reserva) as data, COUNT(*) as qtd
                    FROM vendas v
                    JOIN sorteios s ON v.sorteio_id = s.id
                    WHERE (v.cliente_id = ? OR v.id_whatsapp = ?) AND v.status_venda IN ('pago', 'ganhador')
                    GROUP BY v.sorteio_id, s.titulo, s.numero_visual, s.valor_numero, v.forma_pagamento, DATE_FORMAT(v.data_reserva, '%Y-%m-%d %H:%i')
                    ORDER BY data DESC LIMIT 10
                `, [cliente.id, idWhatsapp]);

                vendas.forEach(v => {
                    // LÓGICA DE EXIBIÇÃO DA ORIGEM (PIX, CARTEIRA, MENSAL)
                    let formaStr = 'PIX';
                    if (v.forma_pagamento === 'carteira_credito') {
                        formaStr = 'MENSAL';
                    } else if (v.forma_pagamento === 'carteira_saldo' || v.forma_pagamento === 'carteira') {
                        formaStr = 'CARTEIRA';
                    }

                    const numSorteio = v.numero_visual || v.sorteio_id;

                    historico.push({
                        tipo: 'compra',
                        titulo: `🎮 Compra: ${v.qtd} números\n   🎁 ${v.titulo} #${numSorteio}`,
                        valor: parseFloat(v.valor_numero) * v.qtd,
                        data: new Date(v.data),
                        forma: formaStr
                    });
                });

                // 2. Busca Recargas de Carteira e Ajustes Manuais
                const [recargas] = await pool.execute(`
                    SELECT tipo, valor, descricao, data_transacao as data
                    FROM transacoes_carteira
                    WHERE cliente_id = ? AND tipo NOT IN ('compra_saldo', 'compra_credito')
                    ORDER BY data DESC LIMIT 10
                `, [cliente.id]);

                recargas.forEach(r => {
                    let formaStr = 'PIX (RECARGA)';
                    if (r.tipo === 'ajuste_admin' || r.tipo === 'ajuste_credito') {
                        formaStr = r.descricao.toLowerCase().includes('mensal') ? 'ADMIN (MENSAL)' : 'ADMIN (SALDO)';
                    } else if (r.tipo === 'recarga_manual') {
                        formaStr = 'ADMIN (SALDO)';
                    }

                    historico.push({
                        tipo: 'recarga',
                        titulo: `💰 ${r.descricao || 'Recarga de Saldo'}`,
                        valor: parseFloat(r.valor),
                        data: new Date(r.data),
                        forma: formaStr
                    });
                });

                // 3. Ordena tudo e pega as 10 mais recentes
                historico.sort((a, b) => b.data - a.data);
                const top10 = historico.slice(0, 10);

                if (top10.length === 0) {
                    await sock.sendMessage(participant, { text: '📜 *HISTÓRICO*\n\nVocê ainda não possui transações.' });
                    return;
                }

                // 4. Cabeçalho Personalizado
                let mensagem = `📜 *HISTÓRICO DE JOGADAS E SALDO*\n\n🎯 *${process.env.NOME_CLIENTE}*\n👑━━━━━━━━━━━━━━━\n\n`;

                for (const t of top10) {
                    const dataFormatada = t.data.toLocaleDateString('pt-BR');
                    const horaFormatada = t.data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
                    const sinal = t.tipo === 'recarga' ? '+' : '-';

                    mensagem += `${t.titulo}\n`;
                    mensagem += `   💵 ${sinal}R$ ${t.valor.toFixed(2).replace('.', ',')} (${t.forma})\n`;
                    mensagem += `   📅 ${dataFormatada} às ${horaFormatada}\n\n`;
                }

                mensagem += `━━━━━━━━━━━━━━━\n💡 Use *#saldo* para ver seu saldo atual`;
                await sock.sendMessage(participant, { text: mensagem });
                return;
            } catch (error) {
                console.error('❌ Erro ao consultar histórico:', error);
            }
        }
        // COMANDO: #RETIRADAS (Mostra os prêmios que o cliente ainda não buscou)
        if (!groupJid.endsWith('@g.us') && comandoLimpo === '#retiradas') {
            try {
                const idWhatsapp = participant.split('@')[0].replace(/\D/g, '');
                const cliente = await buscarClienteNaAgenda(idWhatsapp);
                const cId = cliente ? cliente.id : 0;

                // Busca todos os prêmios pendentes deste cliente
                const [premios] = await pool.execute(`
                    SELECT gp.premio, gp.numero_sorteado, gp.data_ganho, s.titulo, s.numero_visual
                    FROM ganhadores_premios gp
                    JOIN sorteios s ON gp.sorteio_id = s.id
                    WHERE (gp.cliente_id = ? OR (gp.id_whatsapp = ? AND gp.id_whatsapp != ''))
                    AND (gp.status_retirada IS NULL OR gp.status_retirada = '' OR LOWER(gp.status_retirada) = 'pendente')
                    ORDER BY gp.id DESC
                `, [cId, idWhatsapp]);

                const totalPendentes = premios.length;

                if (totalPendentes === 0) {
                    await sock.sendMessage(participant, {
                        text: '🎁 *RETIRADAS*\n\nVocê não possui nenhum prêmio pendente para retirar no momento.\n\nContinue jogando e boa sorte! 🍀'
                    });
                    return;
                }

                // Corta o array para pegar apenas os 10 primeiros
                const limiteExibicao = 10;
                const premiosExibir = premios.slice(0, limiteExibicao);

                let msg = `🎁 *SEUS PRÊMIOS PENDENTES*\n\n🎯 *${process.env.NOME_CLIENTE}*\n━━━━━━━━━━━━━━━\n\n`;

                for (const p of premiosExibir) {
                    const numSorteio = p.numero_visual || p.sorteio_id;
                    msg += `🏆 *Prêmio:* ${p.premio}\n`;
                    msg += `🎰 *Sorteio:* ${p.titulo} #${numSorteio}\n`;
                    msg += `🎟️ *Nº Ganhador:* ${p.numero_sorteado}\n\n`;
                }

                msg += `━━━━━━━━━━━━━━━\n`;

                // Lógica inteligente para mostrar o aviso do total
                if (totalPendentes > limiteExibicao) {
                    msg += `⚠️ _Mostrando ${limiteExibicao} de ${totalPendentes} prêmios pendentes._\n\n`;
                } else {
                    msg += `⚠️ _Você tem um total de ${totalPendentes} prêmio(s) pendente(s)._\n\n`;
                }

                msg += `📍 *Atenção:* Retire seus prêmios no ${process.env.NOME_CLIENTE}!`;

                await sock.sendMessage(participant, { text: msg });
                return;
            } catch (error) {
                console.error('❌ Erro ao consultar retiradas:', error);
            }
        }

        // COMANDO: #RECARGA50, #RECARGA 100, etc (aceita espaço ou sem espaço, maiúscula/minúscula)
        if (!groupJid.endsWith('@g.us') && body.match(/^#recarga\s*(\d+)$/i)) {
            try {
                const match = body.match(/^#recarga\s*(\d+)$/i);
                const valor = parseInt(match[1]);

                if (valor < 10) {
                    await sock.sendMessage(participant, {
                        text: '❌ Valor mínimo para recarga: R$ 10,00'
                    });
                    return;
                }

                const idWhatsapp = participant.split('@')[0].replace(/\D/g, '');
                let cliente = await buscarClienteNaAgenda(idWhatsapp);

                if (!cliente) {
                    const nomeWhatsApp = msg.pushName || "Cliente";
                    await criarClienteNaAgenda(idWhatsapp, idWhatsapp, nomeWhatsApp);
                    cliente = await buscarClienteNaAgenda(idWhatsapp);
                }

                // GERAR PIX BLINDADO PARA O MERCADO PAGO
                const idempotencyKey = `recarga-${idWhatsapp}-${Date.now()}`;
                const nomeLimpo = (cliente.nome_fixo || 'Cliente').replace(/[^a-zA-ZÀ-ÿ\s]/g, "").substring(0, 40);

                const pixData = {
                    transaction_amount: valor,
                    description: `Recarga Carteira - ${nomeLimpo}`,
                    payment_method_id: 'pix',
                    payer: {
                        email: `cliente${idwhatsapp}@${process.env.NOME_CLIENTE || 'sorteios'}.com`,
                        first_name: nomeLimpo
                    }
                };

                const response = await axios.post(MERCADOPAGO_API_URL, pixData, {
                    headers: {
                        'Authorization': `Bearer ${MERCADOPAGO_ACCESS_TOKEN}`,
                        'Content-Type': 'application/json',
                        'X-Idempotency-Key': idempotencyKey
                    }
                });

                const pixCode = response.data.point_of_interaction.transaction_data.qr_code;
                const pixBase64 = response.data.point_of_interaction.transaction_data.qr_code_base64;
                const pixId = response.data.id;

                await pool.execute(
                    `INSERT INTO pagamentos_pix (payment_id, cliente_id, valor, status, tipo, qr_code, data_criacao) 
                     VALUES (?, ?, ?, 'pending', 'recarga_carteira', ?, NOW())`,
                    [pixId, cliente.id, valor, pixCode]
                );

                const mensagem = `💰 *RECARGA DE CARTEIRA*\n\n👤 Nome: *${cliente.nome_fixo}*\n💵 Valor: *R$ ${parseFloat(valor).toFixed(2).replace('.', ',')}*\n\n⏱️ O pagamento será confirmado automaticamente!\n💡 Após o pagamento, o crédito será adicionado em até 1 minuto.`;

                const delayInicialRecarga = Math.floor(Math.random() * 3000) + 2000;
                await new Promise(r => setTimeout(r, delayInicialRecarga));

                // 1. Envia as informações da recarga
                await sock.sendMessage(participant, { text: mensagem });

                // 🔥 Delays dinâmicos humanos
                await new Promise(r => setTimeout(r, Math.floor(Math.random() * 2000) + 4000));

                // 2. Envia o aviso do Copia e Cola
                await sock.sendMessage(participant, { text: `📋 *PIX COPIA E COLA:*` });

                await new Promise(r => setTimeout(r, Math.floor(Math.random() * 2000) + 4000));

                // 3. Envia SOMENTE o código
                await sock.sendMessage(participant, { text: pixCode });

                return;
            } catch (error) {
                console.error('❌ Erro ao gerar PIX de recarga:', error?.response?.data || error.message);
                await sock.sendMessage(participant, {
                    text: '❌ Erro ao gerar PIX. Tente novamente mais tarde.'
                });
            }
        }
        // ==========================================
        // COMANDO RESERVA (#) - COM CARTEIRA
        // ==========================================
        if (category && body.startsWith('#') && body !== '#fechar' && body !== '#todos' && body !== '#config') {
            try {
                const [rifas] = await pool.execute(
                    "SELECT * FROM sorteios WHERE categoria = ? AND status = 'ativo' LIMIT 1",
                    [category]
                );
                if (rifas.length === 0) return;
                const rifa = rifas[0];

                let numsDigitados = [];
                let regex = /#(\d+)/g;
                let match;
                while ((match = regex.exec(text)) !== null) numsDigitados.push(parseInt(match[1]));

                if (numsDigitados.length > 0) {
                    const maxNumeros = rifa.qtd_numeros || 25;
                    let numsValidos = [];
                    let numsInvalidos = [];

                    // 1. Bloqueio: Separa os que não existem (ex: #0 ou maior que o limite)
                    for (let n of numsDigitados) {
                        if (n > 0 && n <= maxNumeros) numsValidos.push(n);
                        else numsInvalidos.push(n);
                    }

                    let nums = []; // Esta variável guardará apenas os livres e válidos
                    let numsOcupados = [];

                    // 2. Bloqueio: Verifica no banco se os válidos já estão ocupados
                    if (numsValidos.length > 0) {
                        const placeholders = numsValidos.map(() => '?').join(',');
                        const [checkOcupados] = await pool.execute(
                            `SELECT numero_escolhido FROM vendas WHERE sorteio_id = ? AND numero_escolhido IN (${placeholders})`,
                            [rifa.id, ...numsValidos]
                        );
                        const setOcupados = new Set(checkOcupados.map(r => r.numero_escolhido));

                        for (let n of numsValidos) {
                            if (setOcupados.has(n)) numsOcupados.push(n);
                            else nums.push(n);
                        }
                    }

                    // 3. Avisa no grupo se alguém fez besteira
                    let msgAviso = "";
                    if (numsInvalidos.length > 0) {
                        msgAviso += `⚠️ Os números *${numsInvalidos.join(', ')}* não existem nesta rifa (Limite: ${maxNumeros}).\n`;
                    }
                    if (numsOcupados.length > 0) {
                        msgAviso += `🚫 Os números *${numsOcupados.join(', ')}* já estão reservados ou pagos.\n`;
                    }

                    if (msgAviso !== "") {
                        await sock.sendMessage(groupJid, { text: msgAviso });
                        if (nums.length === 0) return;
                    }
                    const idWhatsapp = participant.split('@')[0].replace(/\D/g, '');
                    const telefone = idWhatsapp;
                    let nomeWhatsApp = msg.pushName || "Cliente";
                    console.log(`📱 ID: ${idWhatsapp} | ${nomeWhatsApp}`);

                    // ========================
                    // SALVAR/BUSCAR NA AGENDA
                    // ========================
                    let clienteId = null;
                    let nomeFinal = nomeWhatsApp;

                    let cliente = await buscarClienteNaAgenda(idWhatsapp);

                    if (!cliente) {
                        await criarClienteNaAgenda(idWhatsapp, telefone, nomeWhatsApp);
                        cliente = await buscarClienteNaAgenda(idWhatsapp);
                    } else if (cliente.nome_whatsapp !== nomeWhatsApp) {
                        await atualizarNomeWhatsApp(idWhatsapp, nomeWhatsApp);
                    }

                    clienteId = cliente ? cliente.id : null;
                    nomeFinal = cliente ? cliente.nome_fixo : nomeWhatsApp;

                    // ========================================
                    // TRAVA DE SEGURANÇA: PAGAMENTO AUTOMÁTICO
                    // ========================================

                    if (clienteId) {
                        const valorTotal = parseFloat(rifa.valor_numero) * nums.length;
                        const pag = await processarPagamentoUnificado(clienteId, valorTotal, rifa.id, `Compra #${nums.join(',')}`);

                        if (pag.success) {
                            let detalhePgto = "";
                            if (pag.pagoComSaldo > 0) detalhePgto += `\n💵 Do Saldo: R$ ${pag.pagoComSaldo.toFixed(2).replace('.', ',')}`;
                            if (pag.pagoComCredito > 0) detalhePgto += `\n💳 Do Mensal: R$ ${pag.pagoComCredito.toFixed(2).replace('.', ',')}`;

                            const formaPgtoFinal = pag.pagoComCredito > 0 ? (pag.pagoComSaldo > 0 ? 'carteira_misto' : 'carteira_credito') : 'carteira_saldo';

                            for (let n of nums) {
                                await pool.execute("INSERT INTO vendas (sorteio_id, numero_escolhido, id_whatsapp, telefone, nome_comprador, status_venda, data_reserva, cliente_id, forma_pagamento, transacao_carteira_id) VALUES (?, ?, ?, ?, ?, 'pago', NOW(), ?, ?, ?)",
                                    [rifa.id, n, idWhatsapp, idWhatsapp, nomeFinal, clienteId, formaPgtoFinal, pag.transacaoId]);
                            }

                            const numVisual = rifa.numero_visual || rifa.id;

                            // SPINTAX NA RESERVA
                            const titulosReserva = [
                                '✅ *RESERVA CONFIRMADA!*',
                                '🎯 *TUDO CERTO COM SUA RESERVA!*',
                                '🚀 *NÚMEROS GARANTIDOS!*',
                                '🍀 *RESERVA REALIZADA COM SUCESSO!*'
                            ];
                            const tituloSorteado = titulosReserva[Math.floor(Math.random() * titulosReserva.length)];

                            await sock.sendMessage(participant, {
                                text: `${tituloSorteado}\n\n🎁 Sorteio: *${rifa.titulo} #${numVisual}*\n🎟️ Números: *${nums.join(', ')}*\n💰 Total: R$ ${valorTotal.toFixed(2).replace('.', ',')}\n${detalhePgto}\n\nBoa sorte! 🍀`
                            });
                            return;
                        } else if (pag.erro === 'Limite Insuficiente' && pag.saldoAtual > 0) {
                            const msgAviso = `⚠️ *SALDO INSUFICIENTE*\n\nSeu saldo na carteira é de somente *R$ ${pag.saldoAtual.toFixed(2).replace('.', ',')}*, mas essa jogada custa *R$ ${valorTotal.toFixed(2).replace('.', ',')}*.\n\nPague o QR Code abaixo com o valor integral para garantir seus números.\n\n💡 *DICA:* Se quiser usar o dinheiro da carteira, você pode adicionar mais saldo enviando o comando *#recargaVALOR* **aqui mesmo nesta conversa com o robô** (Ex: *#recarga10* para recarregar R$ 10).`; await sock.sendMessage(participant, { text: msgAviso });
                        }
                    } // Fim da trava de pagamento
                    // FIM LÓGICA TESTE
                    let sucessos = [];
                    for (let n of nums) {
                        const [check] = await pool.execute(
                            "SELECT id FROM vendas WHERE sorteio_id = ? AND numero_escolhido = ?",
                            [rifa.id, n]
                        );
                        if (check.length === 0) {
                            await pool.execute(
                                "INSERT INTO vendas (sorteio_id, numero_escolhido, telefone, id_whatsapp, nome_comprador, status_venda, data_reserva, cliente_id) VALUES (?, ?, ?, ?, ?, 'reservado', NOW(), ?)",
                                [rifa.id, n, telefone, idWhatsapp, nomeFinal, clienteId]
                            );
                            sucessos.push(n);
                        }
                    }

                    if (sucessos.length > 0) {
                        const unit = parseFloat(rifa.valor_numero);
                        const totalJogada = (unit * sucessos.length).toFixed(2);
                        try {
                            const desc = `${rifa.titulo} - Números: ${sucessos.join(', ')}`;
                            const pagMP = await criarPagamentoMercadoPago(totalJogada, desc, participant, sucessos);
                            for (let n of sucessos) {
                                await pool.execute(
                                    "UPDATE vendas SET payment_id = ? WHERE sorteio_id = ? AND numero_escolhido = ?",
                                    [pagMP.id, rifa.id, n]
                                );
                            }
                            await enviarDadosPagamento(participant, pagMP, rifa, sucessos, totalJogada.replace('.', ','), nomeFinal);
                        } catch (e) {
                            console.error(e);
                        }
                    }
                }
            } catch (e) {
                console.error(e);
            }
        }

        // COMANDO FECHAR
        if (category && (body === '#fechar' || body === '#fecha')) {
            try {
                const [rifas] = await pool.execute(
                    "SELECT * FROM sorteios WHERE categoria = ? AND status = 'ativo' LIMIT 1",
                    [category]
                );
                if (rifas.length > 0) {
                    const rifa = rifas[0];

                    // PRIMEIRO: Extrair idWhatsapp
                    const idWhatsapp = participant.split('@')[0].replace(/\D/g, '');
                    const telefone = idWhatsapp;
                    let nomeWhatsApp = msg.pushName || "Cliente";

                    // =======================
                    // SALVAR/BUSCAR NA AGENDA
                    // =======================
                    let cliente = await buscarClienteNaAgenda(idWhatsapp);

                    if (!cliente) {
                        await criarClienteNaAgenda(idWhatsapp, telefone, nomeWhatsApp);
                        cliente = await buscarClienteNaAgenda(idWhatsapp);
                    } else if (cliente.nome_whatsapp !== nomeWhatsApp) {
                        await atualizarNomeWhatsApp(idWhatsapp, nomeWhatsApp);
                    }

                    let clienteId = cliente ? cliente.id : null;
                    let nomeFinal = cliente ? cliente.nome_fixo : nomeWhatsApp;

                    console.log(`👤 Nome Fechamento: ${nomeFinal}`);
                    const [ocupados] = await pool.execute(
                        "SELECT numero_escolhido FROM vendas WHERE sorteio_id = ?",
                        [rifa.id]
                    );
                    const ocupadosSet = new Set(ocupados.map(r => r.numero_escolhido));

                    let inseridos = [];
                    const total = rifa.qtd_numeros || 25;
                    for (let i = 1; i <= total; i++) {
                        if (!ocupadosSet.has(i)) {
                            await pool.execute(
                                "INSERT INTO vendas (sorteio_id, numero_escolhido, telefone, id_whatsapp, nome_comprador, status_venda, data_reserva, cliente_id) VALUES (?, ?, ?, ?, ?, 'reservado', NOW(), ?)",
                                [rifa.id, i, telefone, idWhatsapp, nomeFinal, clienteId]
                            );
                            inseridos.push(i);
                        }
                    }

                    if (inseridos.length > 0) {
                        const unit = parseFloat(rifa.valor_numero);
                        const tot = (unit * inseridos.length).toFixed(2);

                        // =================================
                        // PAGAMENTO AUTOMÁTICO COM CARTEIRA
                        // =================================
                        if (clienteId) {
                            const carteira = await verificarCarteira(telefone);
                            if (carteira) {
                                const valorTotal = parseFloat(tot);

                                // USA A MESMA INTELIGÊNCIA DO COMANDO # NORMAL (MISTURA SALDO + MENSAL)
                                const pag = await processarPagamentoUnificado(clienteId, valorTotal, rifa.id, `FECHAMENTO - ${inseridos.length} nums`);

                                if (pag.success) {
                                    let detalhePgto = "";
                                    if (pag.pagoComSaldo > 0) detalhePgto += `\n💵 Do Saldo: R$ ${pag.pagoComSaldo.toFixed(2).replace('.', ',')}`;
                                    if (pag.pagoComCredito > 0) detalhePgto += `\n💳 Do Mensal: R$ ${pag.pagoComCredito.toFixed(2).replace('.', ',')}`;

                                    const formaPgtoFinal = pag.pagoComCredito > 0 ? (pag.pagoComSaldo > 0 ? 'carteira_misto' : 'carteira_credito') : 'carteira_saldo';

                                    for (let n of inseridos) {
                                        await pool.execute(
                                            "UPDATE vendas SET status_venda = 'pago', forma_pagamento = ?, transacao_carteira_id = ? WHERE sorteio_id = ? AND numero_escolhido = ?",
                                            [formaPgtoFinal, pag.transacaoId, rifa.id, n]
                                        );
                                    }

                                    const numVisual = rifa.numero_visual || rifa.id;

                                    // SPINTAX NO FECHAMENTO
                                    const msgFechamento = [
                                        `✅ *FECHAMENTO CONFIRMADO!*`,
                                        `🎯 *TUDO NOSSO! FECHAMENTO CONCLUÍDO!*`,
                                        `🚀 *SUCESSO NO FECHAMENTO!*`,
                                        `🍀 *NÚMEROS DO FECHAMENTO GARANTIDOS!*`
                                    ];
                                    const msgFechada = msgFechamento[Math.floor(Math.random() * msgFechamento.length)];

                                    await sock.sendMessage(participant, {
                                        text: `${msgFechada}\n\n🎁 Sorteio: *${rifa.titulo} #${numVisual}*\n🎟️ Números: *${inseridos.join(', ')}*\n💰 Total: R$ ${tot.replace('.', ',')}\n${detalhePgto}\n\nBoa sorte! 🍀`
                                    });

                                    await forcarAtualizacaoImediata(rifa, groupJid);
                                    return; // Pagou com carteira, encerra aqui.
                                } else if (pag.erro === 'Limite Insuficiente' && pag.saldoAtual > 0) {
                                    const msgAviso = `⚠️ *SALDO INSUFICIENTE*\n\nSeu saldo na carteira é de somente *R$ ${pag.saldoAtual.toFixed(2).replace('.', ',')}*, mas esse fechamento custa *R$ ${valorTotal.toFixed(2).replace('.', ',')}*.\n\nPague o QR Code abaixo com o valor integral para garantir os números.\n\n💡 *DICA:* Se quiser usar o dinheiro da carteira, adicione mais saldo enviando o comando *#recargaVALOR* **aqui mesmo nesta conversa com o robô** (Ex: *#recarga10* para recarregar R$ 10).`; await sock.sendMessage(participant, { text: msgAviso });
                                }

                            }
                        }

                        // Fluxo Normal (Gera PIX se não tiver saldo)
                        try {
                            const desc = `${rifa.titulo} - FECHAMENTO - ${inseridos.length} nums`;
                            const pagMP = await criarPagamentoMercadoPago(tot, desc, participant, inseridos);
                            for (let n of inseridos) {
                                await pool.execute(
                                    "UPDATE vendas SET payment_id = ? WHERE sorteio_id = ? AND numero_escolhido = ?",
                                    [pagMP.id, rifa.id, n]
                                );
                            }
                            await enviarDadosPagamento(participant, pagMP, rifa, inseridos, tot.replace('.', ','), nomeFinal);
                            await forcarAtualizacaoImediata(rifa, groupJid);
                        } catch (e) {
                            console.error(e);
                        }
                    } else {
                        await sock.sendMessage(groupJid, { text: "⚠️ Rifa completa!" });
                    }
                }
            } catch (e) {
                console.error(e);
            }
        }
        if (category && body === '#todos') {
            if (groupJid.endsWith('@g.us')) {
                const groupMetadata = await sock.groupMetadata(groupJid);
                const participants = groupMetadata.participants.map(p => p.id);
                let texto = "📢 *ATENÇÃO GALERA!* 📢\n\n";
                participants.forEach(p => texto += `@${p.split('@')[0]} `);
                await sock.sendMessage(groupJid, { text: texto, mentions: participants });
            }
        }

        if (category && body === '#config') {
            await sock.sendMessage(groupJid, {
                text: `🤖 *MENU \n✅ *#01 #02...* - Reservar\n⚡ *#fechar* - Comprar Restantes\n💳 Pagamento automático Pix\n⚙️ *#config* - Ajuda`
            });
        }
    });

    // FAXINA
    setInterval(async () => {
        try {
            const [expirados] = await pool.execute(`
                SELECT v.sorteio_id, s.categoria, v.numero_escolhido 
                FROM vendas v 
                JOIN sorteios s ON v.sorteio_id = s.id 
                WHERE v.status_venda = 'reservado' 
                AND v.data_reserva < (NOW() - INTERVAL 10 MINUTE)
                AND s.status = 'ativo'
            `);
            if (expirados.length > 0) {
                const mapS = {};
                expirados.forEach(ex => {
                    if (!mapS[ex.sorteio_id]) mapS[ex.sorteio_id] = { cat: ex.categoria, nums: [] };
                    mapS[ex.sorteio_id].nums.push(ex.numero_escolhido);
                });

                for (const [sid, dados] of Object.entries(mapS)) {
                    await pool.query(`DELETE FROM vendas WHERE sorteio_id = ${sid} AND numero_escolhido IN (${dados.nums.join(',')})`);
                    const gid = Object.keys(GRUPOS).find(k => GRUPOS[k] === dados.cat);
                    if (gid && global.sock) {
                        await global.sock.sendMessage(gid, {
                            text: `⚠️ *NÚMEROS LIBERADOS:* ${dados.nums.join(', ')} por falta de pagamento!`
                        });
                        const [r] = await pool.execute("SELECT * FROM sorteios WHERE id = ?", [sid]);
                        if (r.length > 0) await forcarAtualizacaoImediata(r[0], gid);
                    }
                }
            }
        } catch (e) {
            console.error("Erro faxina:", e);
        }
    }, 60000);
    setInterval(async () => {
        try {
            const [prontos] = await pool.execute("SELECT * FROM sorteios WHERE status = 'video_pronto'");

            for (const rifa of prontos) {
                // Trava imediatamente para não enviar mensagens duplicadas
                await pool.execute("UPDATE sorteios SET status = 'finalizando' WHERE id = ?", [rifa.id]);

                const gid = Object.keys(GRUPOS).find(k => GRUPOS[k] === rifa.categoria);
                if (!gid) continue;

                console.log(`🎬 Vídeo do Sorteio ${rifa.id} pronto! Enviando resultados...`);

                // 1. Busca ganhadores unindo com a tabela vendas para pegar o TELEFONE
                const [ganhadores] = await pool.execute(`
                SELECT gp.*, v.telefone 
                FROM ganhadores_premios gp
                JOIN vendas v ON gp.sorteio_id = v.sorteio_id AND gp.numero_sorteado = v.numero_escolhido
                WHERE gp.sorteio_id = ?
                ORDER BY gp.id ASC
            `, [rifa.id]);

                // 2. Marca as vendas originais como 'ganhador'
                for (const g of ganhadores) {
                    await pool.execute("UPDATE vendas SET status_venda = 'ganhador' WHERE sorteio_id = ? AND numero_escolhido = ?", [rifa.id, g.numero_sorteado]);
                }

                // 3. Monta a mensagem de resultado
                const emojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
                const numVisual = String(rifa.numero_visual || rifa.id).padStart(2, '0');
                let msgRes = `🏆 *RESULTADO OFICIAL - SORTEIO AUTOMÁTICO*\n\n🎰 *${rifa.titulo} #${numVisual}*\n\n`;
                ganhadores.forEach((g, index) => {
                    msgRes += `${emojis[index] || `${index + 1}º`} *${g.premio}*\n👤 ${g.nome_cliente} — *Nº ${g.numero_sorteado}*\n\n`;
                });
                msgRes += `_Retire seu prêmio em 48h no ${process.env.NOME_CLIENTE}_`;

                // 4. Caminho da pasta Uploads (Dinâmico)
                const dirUploads = path.join(__dirname, '../assets/uploads');
                const videoPath = path.join(dirUploads, `sorteio_${rifa.id}.mp4`);
                const imagePath = path.join(dirUploads, `sorteio_${rifa.id}.jpg`);

                // 5. Envia o Vídeo primeiro e depois o Print com os ganhadores
                if (fs.existsSync(videoPath)) {
                    await global.sock.sendMessage(gid, { video: fs.readFileSync(videoPath), caption: "🎬 *Sorteio Realizado!* A roleta girou:" });
                    const pausaHumanaVideo = Math.floor(Math.random() * 4000) + 6000;
                    await new Promise(r => setTimeout(r, pausaHumanaVideo));
                }
                if (fs.existsSync(imagePath)) {
                    await global.sock.sendMessage(gid, { image: fs.readFileSync(imagePath), caption: msgRes });
                } else {
                    await global.sock.sendMessage(gid, { text: msgRes });
                }

                // 6. Avisa no Privado dos Ganhadores
                for (const [index, g] of ganhadores.entries()) {
                    if (g.telefone) {
                        try {
                            let userJid = g.telefone.toString().replace(/\D/g, '');
                            let isLid = userJid.length >= 14;
                            userJid = isLid ? `${userJid}@lid` : `${userJid}@s.whatsapp.net`;

                            const agora = new Date();
                            const dataFormatada = agora.toLocaleDateString('pt-BR');
                            const horaFormatada = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

                            const msgPrivada = `🎉 *PARABÉNS! VOCÊ GANHOU!*\n\n🎰 *Sorteio:* ${rifa.titulo}\n🏅 *Colocação:* ${emojis[index] || `${index + 1}º`} Lugar\n🎁 *Prêmio:* ${g.premio}\n🎟️ *Número:* ${g.numero_sorteado}\n📅 *Data:* ${dataFormatada} às ${horaFormatada}\n\n⚠️ *Você tem 48hrs para retirar no ${process.env.NOME_CLIENTE}!*`;

                            await global.sock.sendMessage(userJid, { text: msgPrivada });
                            await new Promise(r => setTimeout(r, Math.floor(Math.random() * 3000) + 4000));
                        } catch (e) { console.error(`Erro ao avisar ganhador:`, e.message); }
                    }
                }

                // 7. Sorteio 100% finalizado!
                await pool.execute("UPDATE sorteios SET status = 'finalizado' WHERE id = ?", [rifa.id]);
                console.log(`✅ Sorteio ${rifa.id} totalmente finalizado!`);

                // 8. PUXAR O PRÓXIMO DA FILA (PLAYLIST)
                try {
                    const [fila] = await pool.execute("SELECT * FROM sorteios WHERE categoria = ? AND status = 'fila' ORDER BY ordem_fila ASC LIMIT 1", [rifa.categoria]);

                    if (fila.length > 0) {
                        const proximo = fila[0];
                        await pool.execute("UPDATE sorteios SET status = 'ativo', ordem_fila = 0 WHERE id = ?", [proximo.id]);

                        const numFormatado = String(proximo.numero_visual || proximo.id).padStart(2, '0');
                        const msgNovo = `🚨 *Os Sorteios Não Param...* 🚨\n\nO sorteio *${proximo.titulo} #${numFormatado}* acabou de entrar na mesa!\n\n👉 Envie *#numero* ou *#fechar* para garantir sua vaga antes que acabe!`;

                        await global.sock.sendMessage(gid, { text: msgNovo });
                        console.log(`🔄 PLAYLIST: Sorteio ${proximo.id} (Fila) ativado automaticamente na categoria ${rifa.categoria}!`);
                    } else {
                        console.log(`⏸️ Fila vazia para a categoria ${rifa.categoria}. Aguardando novos sorteios no painel.`);
                    }
                } catch (errFila) {
                    console.error("Erro ao puxar da fila:", errFila);
                }
            }
        } catch (error) {
            console.error("Erro no Radar de Vídeos:", error);
        }
    }, 5000);

    // MONITOR
    setInterval(async () => {
        try {
            const [ativos] = await pool.execute("SELECT * FROM sorteios WHERE status = 'ativo'");

            for (const rifa of ativos) {
                const [v] = await pool.execute("SELECT COUNT(*) as t FROM vendas WHERE sorteio_id = ?", [rifa.id]);
                const [p] = await pool.execute("SELECT COUNT(*) as t FROM vendas WHERE sorteio_id = ? AND status_venda = 'pago'", [rifa.id]);

                const totalVendas = v[0].t;
                const totalPagos = p[0].t;
                const statusCalculado = `${totalVendas}_${totalPagos}`;
                const gid = Object.keys(GRUPOS).find(k => GRUPOS[k] === rifa.categoria);

                let precisaEnviarFinal = false;

                if (gid) {
                    if (!sorteiosNotificados.has(rifa.id)) {
                        sorteiosNotificados.add(rifa.id);
                        ultimoStatusPorSorteio[rifa.id] = statusCalculado;

                        // CHAMA AS MÍDIAS PRIMEIRO:
                        await enviarMidiasIniciais(rifa, gid);

                        await enviarListaAtualizada(rifa, gid);
                    } else if (statusCalculado !== ultimoStatusPorSorteio[rifa.id]) {
                        ultimoStatusPorSorteio[rifa.id] = statusCalculado;
                        const totalMaximo = rifa.qtd_numeros || 25;

                        if (totalPagos >= totalMaximo) {
                            // 100% PAGO: Avisa pro sistema disparar a lista final e girar o globo
                            precisaEnviarFinal = true;
                        } else if (totalVendas >= totalMaximo) {
                            // 100% RESERVADO: Fura o cronômetro e manda a lista de "Aguardando Pagamentos" na hora!
                            await forcarAtualizacaoImediata(rifa, gid);
                        } else {
                            // AINDA TEM VAGA: Aplica o agendamento inteligente de 1 minuto
                            agendarAtualizacaoLista(rifa, gid);
                        }
                    }
                }

                if (totalPagos >= (rifa.qtd_numeros || 25)) {
                    if (gid && !processandoFinalizacao.has(rifa.id)) {
                        processandoFinalizacao.add(rifa.id);
                        const [verificaStatus] = await pool.execute("SELECT status FROM sorteios WHERE id = ?", [rifa.id]);
                        if (verificaStatus[0].status === 'ativo') {
                            if (precisaEnviarFinal) {
                                await forcarAtualizacaoImediata(rifa, gid);
                            }

                            console.log(`🎥 Sorteio ${rifa.id} (${rifa.categoria.toUpperCase()}) completo! Chamando o Gravador...`);

                            // AVISA NO GRUPO QUE O GLOBO VAI RODAR
                            await global.sock.sendMessage(gid, { text: "🎰 *O globo está girando, boa sorte!*" });
                            await new Promise(r => setTimeout(r, 1000));
                            await pool.execute("UPDATE sorteios SET status = 'gravar_video' WHERE id = ?", [rifa.id]);
                        }
                    }
                }
            }
        } catch (e) { }
    }, 5000);
}

async function criarPagamentoMercadoPago(valorTotal, descricao, telefoneBruto, numerosReservados) {
    const numLimpo = telefoneBruto.split('@')[0].split(':')[0].replace(/\D/g, '');
    const emailPayer = `${numLimpo}@whatsapp.com`;

    // 🕒 ADICIONANDO A VALIDADE DE EXATAMENTE 10 MINUTOS A PARTIR DE AGORA:
    const dataExpiracao = new Date(Date.now() + 10 * 60 * 1000).toISOString();

    const paymentData = {
        transaction_amount: parseFloat(valorTotal),
        description: descricao,
        payment_method_id: 'pix',
        date_of_expiration: dataExpiracao, // <-- TRAVA O PIX EM 10 MINUTOS AQUI!
        payer: {
            email: emailPayer,
            first_name: 'Cliente' // Sem CPF, apenas um primeiro nome genérico
        },
        notification_url: `${process.env.APP_URL}/webhook_mp.php`,
        external_reference: `${numLimpo}|${numerosReservados.join(',')}`,
        metadata: {
            telefone: numLimpo,
            numeros: numerosReservados.join(',')
        }
    };

    const idempotencyKey = `${numLimpo}-${Date.now()}-${numerosReservados.join('')}`;
    const response = await axios.post(MERCADOPAGO_API_URL, paymentData, {
        headers: {
            'Authorization': `Bearer ${MERCADOPAGO_ACCESS_TOKEN}`,
            'Content-Type': 'application/json',
            'X-Idempotency-Key': idempotencyKey
        }
    });

    return response.data;
}

async function enviarDadosPagamento(telefoneBruto, pagamentoInfo, rifa, numerosReservados, valorTotal, nomeCliente) {
    const pixCode = pagamentoInfo.point_of_interaction?.transaction_data?.qr_code;
    if (!pixCode || !global.sock) return;

    const jidEnvio = telefoneBruto;

    // 🔥 1. QUEBRA DE CONCORRÊNCIA: Gera um atraso inicial aleatório entre 2 e 6 segundos para desencontrar disparos simultâneos
    const delayInicial = Math.floor(Math.random() * 4000) + 2000;
    await new Promise(r => setTimeout(r, delayInicial));

    // --- INÍCIO DA CAMUFLAGEM AVANÇADA (NOME + SPINTAX + PROTOCOLO) ---
    const saudacoes = ['Olá', 'Oi', 'Opa', 'Tudo bem', 'Pronto'];
    const saudacao = saudacoes[Math.floor(Math.random() * saudacoes.length)];
    const protocolo = Math.floor(Math.random() * 90000) + 10000;
    const nomeFormatado = nomeCliente ? `*${nomeCliente}*` : 'Cliente';

    let mensagem = `${saudacao}, ${nomeFormatado}!\n\n💳 *PAGAMENTO - MERCADO PAGO*\n\n🎁 *Sorteio:* ${rifa.titulo}\n🎫 *Números:* ${numerosReservados.join(', ')}\n💰 *Valor Total:* R$ ${valorTotal}\n⏰ *Pagamento expira em 10 minutos*\n\n_Ref: ${protocolo}_`;
    // --- FIM DA CAMUFLAGEM ---

    try {
        await global.sock.sendMessage(jidEnvio, { text: mensagem });

        // 🔥 2. DELAYS DINÂMICOS: Pausa flutuante entre 4 e 6 segundos para simular digitação real
        await new Promise(r => setTimeout(r, Math.floor(Math.random() * 2000) + 4000));

        await global.sock.sendMessage(jidEnvio, { text: `📋 *PIX COPIA E COLA:*` });

        // Outra pausa flutuante antes de soltar o código do PIX
        await new Promise(r => setTimeout(r, Math.floor(Math.random() * 2000) + 4000));

        await global.sock.sendMessage(jidEnvio, { text: pixCode });
        console.log(`✅ [PIX ENVIADO] Para: ${jidEnvio}`);
    } catch (e) {
        console.error(`❌ [ERRO PIX] Para: ${jidEnvio}`, e.message);
    }
}
// ==========================================
// VERIFICADOR AUTOMÁTICO (RADAR MESTRE: RECARGAS E RIFAS)
// ==========================================
setInterval(async () => {
    try {
        // --- 1. RADAR DE RECARGAS DA CARTEIRA E FATURAS ---
        const [pendentes] = await pool.execute("SELECT * FROM pagamentos_pix WHERE status = 'pending'");

        for (const recarga of pendentes) {
            try {
                const response = await axios.get(`${MERCADOPAGO_API_URL}/${recarga.payment_id}`, {
                    headers: { 'Authorization': `Bearer ${MERCADOPAGO_ACCESS_TOKEN}` }
                });

                if (response.data.status === 'approved') {
                    const clienteId = recarga.cliente_id;
                    const valor = parseFloat(recarga.valor);

                    // Marca o Pix como aprovado no banco
                    await pool.execute("UPDATE pagamentos_pix SET status = 'approved' WHERE id = ?", [recarga.id]);

                    if (recarga.tipo === 'fatura_mensal') {
                        // 1. ZERA A DÍVIDA DO CLIENTE
                        await pool.execute("UPDATE carteiras SET credito_usado = 0 WHERE cliente_id = ?", [clienteId]);

                        // 2. BUSCA O LIMITE PARA A MENSAGEM (CORREÇÃO)
                        const [cartDados] = await pool.execute("SELECT credito_limite FROM carteiras WHERE cliente_id = ?", [clienteId]);
                        const limiteDisponivel = cartDados[0] ? parseFloat(cartDados[0].credito_limite) : 0;

                        await pool.execute("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES (?, 'ajuste_admin', ?, 'Pagamento da Fatura Mensal (PIX Automático)', NOW())", [clienteId, valor]);

                        const [clienteData] = await pool.execute("SELECT id_whatsapp FROM agenda_clientes WHERE id = ?", [clienteId]);
                        if (clienteData.length > 0 && global.sock) {
                            let jidEnvio = clienteData[0].id_whatsapp;
                            jidEnvio = jidEnvio.length >= 14 ? `${jidEnvio}@lid` : `${jidEnvio}@s.whatsapp.net`;

                            // MENSAGEM ATUALIZADA COM NOME E LIMITE
                            await global.sock.sendMessage(jidEnvio, {
                                text: `✅ *FATURA PAGA COM SUCESSO!*\n\n💰 Valor Pago: R$ ${valor.toFixed(2).replace('.', ',')}\n💳 Seu limite disponível agora é: *R$ ${limiteDisponivel.toFixed(2).replace('.', ',')}*\n\nO seu crédito mensal na *${process.env.NOME_CLIENTE} Sorteios* foi reestabelecido! 🍀`
                            });
                        }
                    } else {
                        // RECARGA DE SALDO NORMAL
                        const [carteiras] = await pool.execute("SELECT * FROM carteiras WHERE cliente_id = ?", [clienteId]);
                        let saldoNovo = valor;

                        if (carteiras.length === 0) {
                            await pool.execute("INSERT INTO carteiras (cliente_id, saldo, credito_limite, credito_usado, status) VALUES (?, ?, 0, 0, 'ativo')", [clienteId, valor]);
                        } else {
                            saldoNovo = parseFloat(carteiras[0].saldo) + valor;
                            await pool.execute("UPDATE carteiras SET saldo = ? WHERE cliente_id = ?", [saldoNovo, clienteId]);
                        }

                        await pool.execute("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, saldo_anterior, saldo_novo, descricao) VALUES (?, 'recarga_pix', ?, ?, ?, 'Recarga Pix via WhatsApp')",
                            [clienteId, valor, carteiras.length > 0 ? carteiras[0].saldo : 0, saldoNovo]);

                        const [clienteData] = await pool.execute("SELECT id_whatsapp, nome_fixo FROM agenda_clientes WHERE id = ?", [clienteId]);

                        if (clienteData.length > 0 && global.sock) {
                            let jidEnvio = clienteData[0].id_whatsapp;
                            jidEnvio = jidEnvio.length >= 14 ? `${jidEnvio}@lid` : `${jidEnvio}@s.whatsapp.net`;
                            await global.sock.sendMessage(jidEnvio, {
                                text: `✅ *RECARGA APROVADA!*\n\n💰 Valor: R$ ${valor.toFixed(2).replace('.', ',')}\n💵 Seu saldo atual é: *R$ ${saldoNovo.toFixed(2).replace('.', ',')}*\n\nVocê já pode comprar seus números direto pelo saldo!`
                            });
                        }
                    }
                } else if (response.data.status === 'cancelled' || response.data.status === 'rejected') {
                    await pool.execute("UPDATE pagamentos_pix SET status = 'cancelled' WHERE id = ?", [recarga.id]);
                }
            } catch (err) { }
        }
        // --- 2. RADAR DE SORTEIOS NORMAIS (SEGURANÇA MÁXIMA) ---
        const [vendasPendentes] = await pool.execute(`
                SELECT DISTINCT payment_id, telefone 
                FROM vendas 
                WHERE status_venda = 'reservado' 
                AND payment_id IS NOT NULL 
                AND data_reserva > (NOW() - INTERVAL 15 MINUTE)
            `);

        for (const venda of vendasPendentes) {
            try {
                const response = await axios.get(`${MERCADOPAGO_API_URL}/${venda.payment_id}`, {
                    headers: { 'Authorization': `Bearer ${MERCADOPAGO_ACCESS_TOKEN}` }
                });

                if (response.data.status === 'approved') {
                    // Pega os números exatos atrelados a esse Pix direto do banco
                    const [numerosDB] = await pool.execute("SELECT numero_escolhido FROM vendas WHERE payment_id = ? AND status_venda = 'reservado'", [venda.payment_id]);

                    if (numerosDB.length > 0) {
                        const numeros = numerosDB.map(r => r.numero_escolhido);
                        const telefone = venda.telefone;

                        // 1. Atualiza no banco para 'pago'
                        for (const numero of numeros) {
                            await pool.execute(
                                "UPDATE vendas SET status_venda = 'pago', data_reserva = NOW() WHERE payment_id = ? AND numero_escolhido = ?",
                                [venda.payment_id, numero]
                            );
                        }

                        // 2. Busca os dados do sorteio para mandar a mensagem
                        const [vendasAtualizadas] = await pool.execute(
                            "SELECT DISTINCT sorteio_id FROM vendas WHERE payment_id = ?",
                            [venda.payment_id]
                        );

                        if (vendasAtualizadas.length > 0) {
                            const sid = vendasAtualizadas[0].sorteio_id;
                            const [rifas] = await pool.execute("SELECT * FROM sorteios WHERE id = ?", [sid]);

                            if (rifas.length > 0 && global.sock) {
                                let jidEnvio = telefone.toString().replace(/\D/g, '');
                                jidEnvio = jidEnvio.length >= 14 ? `${jidEnvio}@lid` : `${jidEnvio}@s.whatsapp.net`;

                                const saudacoes = ['Oba!', 'Maravilha!', 'Sucesso!', 'Show!', 'Tudo certo!'];
                                const saudacao = saudacoes[Math.floor(Math.random() * saudacoes.length)];
                                const protocolo = Math.floor(Math.random() * 90000) + 10000;

                                // Quebra de concorrência no Radar
                                const delayRadar = Math.floor(Math.random() * 3000) + 2000;
                                await new Promise(r => setTimeout(r, delayRadar));

                                await global.sock.sendMessage(jidEnvio, {
                                    text: `✅ ${saudacao} Pagamento confirmado.\n\n🎁 Rifa: ${rifas[0].titulo}\n🎫 Números: ${numeros.join(', ')}\n🍀 Boa sorte!\n\n_Recibo: ${protocolo}_`
                                });

                                // 3. Agrupa a atualização da lista no grupo (evita spam se aprovar vários PIXs juntos)
                                const groupId = Object.keys(GRUPOS).find(k => GRUPOS[k] === rifas[0].categoria);
                                if (groupId) {
                                    agendarAtualizacaoLista(rifas[0], groupId);
                                }
                            }
                        }
                    }
                }
            } catch (e) { }
        }
        // --- 3. RADAR DE CANCELAMENTOS E ESTORNOS ---
        const [sorteiosCancelados] = await pool.execute(`
    SELECT id, titulo, numero_visual, valor_numero 
    FROM sorteios 
    WHERE status = 'cancelado'
`);

        for (const rifa of sorteiosCancelados) {
            // 🔥 TRAVA ATÔMICA: Se não conseguir marcar como 'processando', o robô pula essa rifa
            const [result] = await pool.execute("UPDATE sorteios SET status = 'processando_estorno' WHERE id = ? AND status = 'cancelado'", [rifa.id]);

            if (result.affectedRows === 0) continue; // Alguém já pegou essa rifa, ignora!

            const [vendasPagas] = await pool.execute(
                "SELECT id_whatsapp, telefone, cliente_id, nome_comprador, numero_escolhido FROM vendas WHERE sorteio_id = ? AND status_venda = 'pago'",
                [rifa.id]
            );
            if (vendasPagas.length > 0) {
                // Agrupa as jogadas por cliente (para devolver tudo de uma vez)
                const clientesAfetados = {};
                vendasPagas.forEach(v => {
                    const key = v.id_whatsapp || v.telefone;
                    if (!clientesAfetados[key]) {
                        clientesAfetados[key] = {
                            cliente_id: v.cliente_id,
                            telefone: v.telefone,
                            nome: v.nome_comprador,
                            numeros: [],
                            id_whatsapp: v.id_whatsapp
                        };
                    }
                    clientesAfetados[key].numeros.push(v.numero_escolhido);
                });

                const valorNumero = parseFloat(rifa.valor_numero);
                const numVisual = rifa.numero_visual || rifa.id;

                // Faz o estorno e avisa cada cliente no privado
                for (const key in clientesAfetados) {
                    const c = clientesAfetados[key];
                    const totalEstorno = c.numeros.length * valorNumero;
                    let cId = c.cliente_id;

                    // 1. Acha ou cria o cliente na Agenda
                    if (!cId) {
                        const agenda = await buscarClienteNaAgenda(c.id_whatsapp);
                        if (agenda) {
                            cId = agenda.id;
                        } else {
                            await criarClienteNaAgenda(c.id_whatsapp, c.telefone, c.nome || "Cliente");
                            const newAgenda = await buscarClienteNaAgenda(c.id_whatsapp);
                            cId = newAgenda.id;
                        }
                    }

                    // 2. Adiciona o dinheiro na Carteira
                    const [carteiras] = await pool.execute("SELECT id, saldo FROM carteiras WHERE cliente_id = ?", [cId]);
                    let saldoNovo = totalEstorno;

                    if (carteiras.length === 0) {
                        await pool.execute("INSERT INTO carteiras (cliente_id, saldo, credito_limite, credito_usado, status, data_criacao) VALUES (?, ?, 0, 0, 'ativo', NOW())", [cId, totalEstorno]);
                    } else {
                        saldoNovo = parseFloat(carteiras[0].saldo) + totalEstorno;
                        await pool.execute("UPDATE carteiras SET saldo = ? WHERE cliente_id = ?", [saldoNovo, cId]);
                    }

                    // 3. Salva o recibo bonito do Estorno no Raio-X
                    await pool.execute("INSERT INTO transacoes_carteira (cliente_id, tipo, valor, descricao, data_transacao) VALUES (?, 'estorno', ?, ?, NOW())",
                        [cId, totalEstorno, `Cancelamento: ${rifa.titulo} #${numVisual}`]
                    );

                    // 4. Marca os números como estornados no banco
                    for (let n of c.numeros) {
                        await pool.execute("UPDATE vendas SET status_venda = 'estornado' WHERE sorteio_id = ? AND numero_escolhido = ?", [rifa.id, n]);
                    }

                    // 5. Manda a mensagem pro cliente informando a devolução
                    let jidEnvio = key;
                    jidEnvio = jidEnvio.length >= 14 ? `${jidEnvio}@lid` : `${jidEnvio}@s.whatsapp.net`;

                    const msg = `⚠️ *AVISO IMPORTANTE - SORTEIO CANCELADO* ⚠️\n\nOlá, ${c.nome || 'Cliente'}!\nO sorteio *${rifa.titulo} #${numVisual}* infelizmente precisou ser cancelado pela administração.\n\n🎟️ *Seus números eram:* ${c.numeros.join(', ')}\n💰 *Valor pago:* R$ ${totalEstorno.toFixed(2).replace('.', ',')}\n\n✅ *SEU DINHEIRO ESTÁ SEGURO!*\nO valor integral que você pagou foi creditado agora mesmo na sua *Carteira Digital* do nosso sistema.\n\nNa sua próxima jogada, o robô usará esse saldo automaticamente caso você tenha o valor completo da rifa!\n\n💡 _Use o comando *#saldo* aqui nesta conversa privada com o robô para ver sua conta._`;
                    if (global.sock) {
                        await global.sock.sendMessage(jidEnvio, { text: msg });
                        // Pausa flutuante (entre 4s e 7s) para simular humano devolvendo
                        await new Promise(r => setTimeout(r, Math.floor(Math.random() * 3000) + 4000));
                    }
                }

                // MÁGICA: Após devolver o dinheiro de todos, o robô arquiva a rifa para não ler de novo
                await pool.execute("UPDATE sorteios SET status = 'inativo' WHERE id = ?", [rifa.id]);

            } else {
                // Se a rifa foi cancelada mas ninguém tinha pago, o robô só arquiva ela silenciosamente
                await pool.execute("UPDATE sorteios SET status = 'inativo' WHERE id = ?", [rifa.id]);
            }
        }
    } catch (error) {
        console.error("Erro no radar mestre:", error);
    }
}, 10000);
// RADAR DE VÍDEOS PRONTOS (CINEGRAFISTA TERMINOU)
// 🧹 FAXINA AUTOMÁTICA (A cada 12 horas)

setInterval(() => {
    const dirUploads = path.join(__dirname, '../assets/uploads');

    if (!fs.existsSync(dirUploads)) return;

    const diasParaApagar = 7;
    const tempoLimite = diasParaApagar * 24 * 60 * 60 * 1000; // 7 dias em milissegundos
    const agora = Date.now();

    fs.readdir(dirUploads, (err, files) => {
        if (err) return console.error("Erro na faxina:", err);

        files.forEach(file => {
            // SEGURANÇA: Só mexe em arquivos que começam com 'sorteio_'
            if (file.startsWith('sorteio_') && (file.endsWith('.mp4') || file.endsWith('.jpg'))) {
                const filePath = path.join(dirUploads, file);

                fs.stat(filePath, (err, stats) => {
                    if (err) return;

                    // Só apaga se a idade (agora - data de modificação) for MAIOR que 7 dias
                    if ((agora - stats.mtimeMs) > tempoLimite) {
                        fs.unlink(filePath, err => {
                            if (!err) console.log(`🗑️ Limpeza: ${file} removido da VPS.`);
                        });
                    }
                });
            }
        });
    });
}, 12 * 60 * 60 * 1000); // Executa a verificação a cada 12 horas
// RADAR DE VÍDEOS PRONTOS (CINEGRAFISTA TERMINOU)
// ==========================================
setInterval(async () => {
    try {
        const [prontos] = await pool.execute("SELECT * FROM sorteios WHERE status = 'video_pronto'");

        for (const rifa of prontos) {
            // Trava imediatamente para não enviar mensagens duplicadas
            await pool.execute("UPDATE sorteios SET status = 'finalizando' WHERE id = ?", [rifa.id]);

            const gid = Object.keys(GRUPOS).find(k => GRUPOS[k] === rifa.categoria);
            if (!gid) continue;

            console.log(`🎬 Vídeo do Sorteio ${rifa.id} pronto! Enviando resultados...`);

            // 1. Busca ganhadores unindo com a tabela vendas para pegar o TELEFONE
            const [ganhadores] = await pool.execute(`
                SELECT gp.*, v.telefone 
                FROM ganhadores_premios gp
                JOIN vendas v ON gp.sorteio_id = v.sorteio_id AND gp.numero_sorteado = v.numero_escolhido
                WHERE gp.sorteio_id = ?
                ORDER BY gp.id ASC
            `, [rifa.id]);

            // 2. Marca as vendas originais como 'ganhador'
            for (const g of ganhadores) {
                await pool.execute("UPDATE vendas SET status_venda = 'ganhador' WHERE sorteio_id = ? AND numero_escolhido = ?", [rifa.id, g.numero_sorteado]);
            }

            // 3. Monta a mensagem de resultado
            const emojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
            const numVisual = String(rifa.numero_visual || rifa.id).padStart(2, '0');
            let msgRes = `🏆 *RESULTADO OFICIAL - SORTEIO AUTOMÁTICO*\n\n🎰 *${rifa.titulo} #${numVisual}*\n\n`;
            ganhadores.forEach((g, index) => {
                msgRes += `${emojis[index] || `${index + 1}º`} *${g.premio}*\n👤 ${g.nome_cliente} — *Nº ${g.numero_sorteado}*\n\n`;
            });
            msgRes += `_Retire seu prêmio em 48h no ${process.env.NOME_CLIENTE}_`;

            // 4. Caminho da pasta Uploads (Dinâmico)
            const dirUploads = path.join(__dirname, '../assets/uploads');
            const videoPath = path.join(dirUploads, `sorteio_${rifa.id}.mp4`);
            const imagePath = path.join(dirUploads, `sorteio_${rifa.id}.jpg`);

            // 5. Envia o Vídeo primeiro e depois o Print com os ganhadores
            if (fs.existsSync(videoPath)) {
                await global.sock.sendMessage(gid, { video: fs.readFileSync(videoPath), caption: "🎬 *Sorteio Realizado!* A roleta girou:" });
                const pausaHumanaVideo = Math.floor(Math.random() * 4000) + 6000;
                await new Promise(r => setTimeout(r, pausaHumanaVideo));
            }
            if (fs.existsSync(imagePath)) {
                await global.sock.sendMessage(gid, { image: fs.readFileSync(imagePath), caption: msgRes });
            } else {
                await global.sock.sendMessage(gid, { text: msgRes });
            }

            // 6. Avisa no Privado dos Ganhadores
            for (const [index, g] of ganhadores.entries()) {
                if (g.telefone) {
                    try {
                        let userJid = g.telefone.toString().replace(/\D/g, '');
                        let isLid = userJid.length >= 14;
                        userJid = isLid ? `${userJid}@lid` : `${userJid}@s.whatsapp.net`;

                        const agora = new Date();
                        const dataFormatada = agora.toLocaleDateString('pt-BR');
                        const horaFormatada = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

                        const msgPrivada = `🎉 *PARABÉNS! VOCÊ GANHOU!*\n\n🎰 *Sorteio:* ${rifa.titulo}\n🏅 *Colocação:* ${emojis[index] || `${index + 1}º`} Lugar\n🎁 *Prêmio:* ${g.premio}\n🎟️ *Número:* ${g.numero_sorteado}\n📅 *Data:* ${dataFormatada} às ${horaFormatada}\n\n⚠️ *Você tem 48hrs para retirar no ${process.env.NOME_CLIENTE}!*`;

                        await global.sock.sendMessage(userJid, { text: msgPrivada });
                        await new Promise(r => setTimeout(r, Math.floor(Math.random() * 3000) + 4000));
                    } catch (e) { console.error(`Erro ao avisar ganhador:`, e.message); }
                }
            }

            // 7. Sorteio 100% finalizado!
            await pool.execute("UPDATE sorteios SET status = 'finalizado' WHERE id = ?", [rifa.id]);
            console.log(`✅ Sorteio ${rifa.id} totalmente finalizado!`);

            // 8. PUXAR O PRÓXIMO DA FILA (PLAYLIST)
            try {
                const [fila] = await pool.execute("SELECT * FROM sorteios WHERE categoria = ? AND status = 'fila' ORDER BY ordem_fila ASC LIMIT 1", [rifa.categoria]);

                if (fila.length > 0) {
                    const proximo = fila[0];
                    await pool.execute("UPDATE sorteios SET status = 'ativo', ordem_fila = 0 WHERE id = ?", [proximo.id]);

                    const numFormatado = String(proximo.numero_visual || proximo.id).padStart(2, '0');
                    const msgNovo = `🚨 *Os Sorteios Não Param...* 🚨\n\nO sorteio *${proximo.titulo} #${numFormatado}* acabou de entrar na mesa!\n\n👉 Envie *#numero* ou *#fechar* para garantir sua vaga antes que acabe!`;

                    await global.sock.sendMessage(gid, { text: msgNovo });
                    console.log(`🔄 PLAYLIST: Sorteio ${proximo.id} (Fila) ativado automaticamente na categoria ${rifa.categoria}!`);
                } else {
                    console.log(`⏸️ Fila vazia para a categoria ${rifa.categoria}. Aguardando novos sorteios no painel.`);
                }
            } catch (errFila) {
                console.error("Erro ao puxar da fila:", errFila);
            }
        }
    } catch (error) {
        console.error("Erro no Radar de Vídeos:", error);
    }
}, 5000);

app.listen(WEBHOOK_PORT, () => console.log(`🌐 API Rodando na porta ${WEBHOOK_PORT}`));
connectToWhatsApp();
// --- TRAVA PARA AVISAR NO PM2 STOP / RESTART ---
process.on('SIGINT', async () => {
    console.log("🛑 Recebido comando STOP/RESTART. Avisando Telegram...");

    const telegramToken = '8641151787:AAF6xmvEjs5E7XpUy5OpRlQKIjwnf8wqgu8';
    const telegramChatId = '8720947602';
    const agora = new Date();
    const isManutencao = (agora.getHours() === 3 && agora.getMinutes() <= 10);

    // SÓ AVISA SE NÃO FOR HORA DE MANUTENÇÃO
    if (!isManutencao) {
        try {
            await axios.post(`https://api.telegram.org/bot${telegramToken}/sendMessage`, {
                chat_id: telegramChatId,
                text: `🛑 *ALERTA D'KING:* O Robô do ${process.env.NOME_CLIENTE} foi *DESLIGADO* manualmente (Stop ou Restart)!\n\n⚠️ _Se você não fez isso, verifique o servidor agora!_`,
                parse_mode: 'Markdown'
            });
            // Pequena pausa para garantir que o Telegram receba antes do processo morrer
            await new Promise(resolve => setTimeout(resolve, 1000));
        } catch (e) {
            console.log("Erro ao avisar desligamento no Telegram");
        }
    }
    process.exit(0);
});