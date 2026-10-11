"""
Microservicio ms-imc (puerto 5001)
Calcula el Índice de Masa Corporal con clasificación geriátrica (adulto mayor).
"""
import os
from flask import Flask, request, jsonify

app = Flask(__name__)


def _numero(valor, nombre, minimo, maximo):
    """Valida que el valor sea numérico (no booleano) y esté en rango."""
    if isinstance(valor, bool) or not isinstance(valor, (int, float)):
        try:
            valor = float(valor)
        except (TypeError, ValueError):
            raise ValueError(f"'{nombre}' debe ser numérico")
    if not (minimo <= valor <= maximo):
        raise ValueError(f"'{nombre}' debe estar entre {minimo} y {maximo}")
    return float(valor)


def clasificar(imc):
    """Rangos geriátricos: <22 bajo peso, 22-27 normal, >27-30 sobrepeso, >30 obesidad."""
    if imc < 22.0:
        return 'Bajo peso / riesgo de desnutrición', 'Atencion', True
    if imc <= 27.0:
        return 'Peso normal (rango geriátrico)', 'Normal', False
    if imc <= 30.0:
        return 'Sobrepeso', 'Normal', False
    return 'Obesidad', 'Atencion', True


@app.get('/health')
def health():
    return jsonify({'status': 'ok', 'servicio': 'ms-imc'}), 200


@app.post('/api/v1/imc')
@app.post('/calcular-imc')  # ruta indicada en el documento de diseño
def calcular_imc():
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify({'error': 'Se esperaba un cuerpo JSON con peso_kg y altura_m'}), 400
    try:
        peso = _numero(data.get('peso_kg'), 'peso_kg', 20, 250)
        altura = _numero(data.get('altura_m'), 'altura_m', 1.0, 2.3)
    except ValueError as e:
        return jsonify({'error': str(e)}), 400

    imc = round(peso / (altura ** 2), 2)
    categoria, nivel, alerta = clasificar(imc)
    return jsonify({
        'imc': imc,
        'categoria': categoria,
        'nivel': nivel,
        'alerta_nutricional': alerta,
        'status': 'success',
    }), 200


if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001, debug=os.getenv('FLASK_DEBUG') == '1')
