// ============================================
// SISTEMA SGL - Scripts principais
// ============================================

function initSGL() {

    // ---- Mascaras ----
    aplicarMascara('[name="cpf_cnpj"]',    mascaraCPFCNPJ);
    aplicarMascara('[name="telefone"]',    mascaraTelefone);
    aplicarMascara('[name="celular"]',     mascaraTelefone);
    aplicarMascara('[name="cep"]',         mascaraCEP);
    aplicarMascara('[name="valor"]',       mascaraMoeda);
    aplicarMascara('[name="valor_total"]', mascaraMoeda);
    aplicarMascara('[name="valor_pago"]',  mascaraMoeda);

    // ---- Auto-calculo de Valor Parcela ----
    var campoTotal    = document.querySelector('[name="valor_total"]');
    var campoParcelas = document.querySelector('[name="qtd_parcelas"]');
    var campoParcela  = document.getElementById('valor_parcela_calc');

    if (campoTotal && campoParcelas && campoParcela) {
        function calcularParcela() {
            // Remove pontos de milhar e substitui a virgula decimal por ponto
            var total    = parseFloat(campoTotal.value.replace(/\./g, '').replace(',', '.')) || 0;
            var parcelas = parseInt(campoParcelas.value, 10) || 1;
            if (parcelas < 1) parcelas = 1;
            
            var parcela = total / parcelas;
            campoParcela.value = 'R$ ' + parcela.toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        campoTotal.addEventListener('input', calcularParcela);
        campoParcelas.addEventListener('input', calcularParcela);
    }

    // ---- Destaque de prazo fatal ----
    var prazoFatal = document.querySelector('[name="prazo_fatal"]');
    if (prazoFatal) {
        prazoFatal.addEventListener('change', function () {
            if (this.value === 'Sim') {
                this.classList.add('border-danger', 'bg-danger', 'text-white');
                mostrarAlerta('ATENÇÃO: Prazo Fatal marcado! Este compromisso não pode ser perdido.', 'danger');
            } else {
                this.classList.remove('border-danger', 'bg-danger', 'text-white');
            }
        });
    }

    // ---- Auto-fill data atual no campo data_evento (Fuso Horário Local) ----
    document.querySelectorAll('input[type="date"]').forEach(function (el) {
        if (!el.value && el.name === 'data_evento') {
            var hoje = new Date();
            var ano = hoje.getFullYear();
            var mes = String(hoje.getMonth() + 1).padStart(2, '0');
            var dia = String(hoje.getDate()).padStart(2, '0');
            el.value = ano + '-' + mes + '-' + dia;
        }
    });

    // ---- Confirmação ao excluir (Delegação de Eventos para suportar linhas dinâmicas) ----
    document.addEventListener('click', function (e) {
        var linkExcluir = e.target.closest('a[href*="excluir"]');
        if (linkExcluir) {
            if (!confirm('Tem certeza que deseja excluir este registro?\nEsta ação não pode ser desfeita.')) {
                e.preventDefault();
            }
        }
    });

    // ---- Highlight de linha clicada (Delegação de Eventos para melhor performance) ----
    var tbodies = document.querySelectorAll('tbody');
    tbodies.forEach(function (tbody) {
        tbody.style.cursor = 'pointer';
        tbody.addEventListener('click', function (e) {
            var tr = e.target.closest('tr');
            if (tr && tbody.contains(tr)) {
                tbody.querySelectorAll('tr').forEach(function (r) {
                    r.classList.remove('table-active');
                });
                tr.classList.add('table-active');
            }
        });
    });

    // ---- Busca local em tabelas em tempo real ----
    var campoBusca = document.getElementById('busca_local');
    if (campoBusca) {
        campoBusca.addEventListener('input', function () {
            var termo = this.value.toLowerCase();
            document.querySelectorAll('tbody tr').forEach(function (tr) {
                var texto = tr.textContent.toLowerCase();
                tr.style.display = texto.indexOf(termo) !== -1 ? '' : 'none';
            });
        });
    }

    // ---- Auto-fechar alertas de sucesso após 4 segundos ----
    document.querySelectorAll('.alert-success, .alert-info').forEach(function (alerta) {
        setTimeout(function () {
            alerta.style.transition = 'opacity 0.5s';
            alerta.style.opacity    = '0';
            setTimeout(function () {
                if (alerta.parentNode) {
                    alerta.parentNode.removeChild(alerta);
                }
            }, 500);
        }, 4000);
    });

    // ---- Busca CEP automática ao sair do campo ----
    var campoCEP = document.querySelector('[name="cep"]');
    if (campoCEP) {
        campoCEP.addEventListener('blur', function () {
            buscarCEP(this.value);
        });
    }
}

// Disparo seguro
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSGL);
} else {
    initSGL();
}

// ============================================
// FUNÇÕES DE MÁSCARA
// ============================================

function aplicarMascara(seletor, funcao) {
    document.querySelectorAll(seletor).forEach(function (el) {
        el.addEventListener('input', function () {
            this.value = funcao(this.value);
        });
    });
}

function mascaraCPFCNPJ(v) {
    v = v.replace(/\D/g, '');
    if (v.length <= 11) {
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    } else {
        v = v.replace(/^(\d{2})(\d)/, '$1.$2');
        v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
        v = v.replace(/(\d{4})(\d)/, '$1-$2');
    }
    return v;
}

function mascaraTelefone(v) {
    v = v.replace(/\D/g, '').slice(0, 11);
    if (v.length <= 10) {
        v = v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
    } else {
        v = v.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3');
    }
    return v;
}

function mascaraCEP(v) {
    v = v.replace(/\D/g, '').slice(0, 8);
    return v.replace(/(\d{5})(\d)/, '$1-$2');
}

function mascaraMoeda(v) {
    v = v.replace(/\D/g, '');
    if (!v) { return ''; }
    v = (parseInt(v, 10) / 100).toFixed(2);
    return v.replace('.', ',');
}

// ============================================
// UTILITÁRIOS
// ============================================

function mostrarAlerta(mensagem, tipo) {
    tipo = tipo || 'warning';
    var div = document.createElement('div');
    div.className = 'alert alert-' + tipo + ' alert-dismissible fade show mt-2';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-close';
    btn.setAttribute('data-bs-dismiss', 'alert');
    
    // Fallback caso não use Bootstrap JS para fechar manualmente
    btn.addEventListener('click', function() {
        div.remove();
    });

    var txt = document.createTextNode(mensagem);
    div.appendChild(txt);
    div.appendChild(btn);

    var targetContainer = document.querySelector('main') || document.body;
    targetContainer.insertBefore(div, targetContainer.firstChild);

    setTimeout(function () {
        div.style.transition = 'opacity 0.5s';
        div.style.opacity = '0';
        setTimeout(function () {
            if (div.parentNode) {
                div.parentNode.removeChild(div);
            }
        }, 500);
    }, 5000);
}

// ---- Busca CEP via ViaCEP ----
function buscarCEP(cep) {
    cep = cep.replace(/\D/g, '');
    if (cep.length !== 8) { return; }

    fetch('https://viacep.com.br/ws/' + cep + '/json/')
        .then(function (res) {
            return res.json();
        })
        .then(function (data) {
            if (data.erro) {
                mostrarAlerta('CEP não encontrado.', 'warning');
                return;
            }
            var mapa = {
                'logradouro': data.logradouro,
                'bairro':     data.bairro,
                'cidade':     data.localidade,
                'estado':     data.uf
            };
            Object.keys(mapa).forEach(function (nome) {
                var el = document.querySelector('[name="' + nome + '"]');
                if (el && mapa[nome]) {
                    el.value = mapa[nome];
                }
            });
        })
        .catch(function () {
            mostrarAlerta('Erro ao buscar CEP. Verifique sua conexão.', 'danger');
        });
}