-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 22-Jan-2026 às 23:49
-- Versão do servidor: 10.4.32-MariaDB
-- versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `dking_premios`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `contador_categorias`
--

CREATE TABLE `contador_categorias` (
  `id` int(11) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `proximo_numero` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `contador_categorias`
--

INSERT INTO `contador_categorias` (`id`, `categoria`, `proximo_numero`) VALUES
(1, 'carnes', 7),
(2, 'bebidas', 2);

-- --------------------------------------------------------

--
-- Estrutura da tabela `ganhadores_premios`
--

CREATE TABLE `ganhadores_premios` (
  `id` int(11) NOT NULL,
  `sorteio_id` int(11) DEFAULT NULL,
  `nome_cliente` varchar(255) DEFAULT NULL,
  `numero_sorteado` int(11) DEFAULT NULL,
  `premio` varchar(255) DEFAULT NULL,
  `status_retirada` varchar(20) DEFAULT 'pendente',
  `data_ganho` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `historico`
--

CREATE TABLE `historico` (
  `id` int(11) NOT NULL,
  `sorteio_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `historico`
--

INSERT INTO `historico` (`id`, `sorteio_id`) VALUES
(1, 1),
(2, 5),
(3, 8);

-- --------------------------------------------------------

--
-- Estrutura da tabela `sorteios`
--

CREATE TABLE `sorteios` (
  `id` int(11) NOT NULL,
  `titulo` varchar(255) DEFAULT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `valor_numero` decimal(10,2) DEFAULT NULL,
  `qtd_numeros` int(11) DEFAULT 25,
  `premios` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'inativo',
  `imagem` varchar(255) DEFAULT NULL,
  `video` varchar(255) DEFAULT NULL,
  `numero_visual` int(11) DEFAULT NULL,
  `data_sorteio` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `vendas`
--

CREATE TABLE `vendas` (
  `id` int(11) NOT NULL,
  `sorteio_id` int(11) DEFAULT NULL,
  `numero_escolhido` int(11) DEFAULT NULL,
  `telefone` varchar(255) DEFAULT NULL,
  `nome_comprador` varchar(255) DEFAULT NULL,
  `status_venda` varchar(20) DEFAULT 'reservado',
  `data_reserva` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `contador_categorias`
--
ALTER TABLE `contador_categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `categoria` (`categoria`);

--
-- Índices para tabela `ganhadores_premios`
--
ALTER TABLE `ganhadores_premios`
  ADD PRIMARY KEY (`id`);

--
-- Índices para tabela `historico`
--
ALTER TABLE `historico`
  ADD PRIMARY KEY (`id`);

--
-- Índices para tabela `sorteios`
--
ALTER TABLE `sorteios`
  ADD PRIMARY KEY (`id`);

--
-- Índices para tabela `vendas`
--
ALTER TABLE `vendas`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `contador_categorias`
--
ALTER TABLE `contador_categorias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `ganhadores_premios`
--
ALTER TABLE `ganhadores_premios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `historico`
--
ALTER TABLE `historico`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `sorteios`
--
ALTER TABLE `sorteios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `vendas`
--
ALTER TABLE `vendas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
