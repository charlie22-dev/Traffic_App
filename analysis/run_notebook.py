"""Execute the notebook with this venv's Python, without global kernel installation."""
import sys
from pathlib import Path
import nbformat
from nbconvert.preprocessors import ExecutePreprocessor
from jupyter_client.kernelspec import KernelSpecManager
from jupyter_client import KernelManager

ROOT = Path(__file__).resolve().parents[1]
output = ROOT / 'analysis/output'
output.mkdir(parents=True, exist_ok=True)
kernels = output / 'kernels' / 'commutelive-eval'
kernels.mkdir(parents=True, exist_ok=True)
import json
(kernels / 'kernel.json').write_text(json.dumps({
    'argv': [sys.executable, '-m', 'ipykernel_launcher', '-f', '{connection_file}'],
    'display_name': 'CommuteLive local evaluation', 'language': 'python',
}), encoding='utf-8')
notebook = nbformat.read(ROOT / 'analysis/eta_eval.ipynb', as_version=4)
manager = KernelManager(kernel_name='commutelive-eval',
                        kernel_spec_manager=KernelSpecManager(kernel_dirs=[str(kernels.parent)]))
executor = ExecutePreprocessor(timeout=600, kernel_name='commutelive-eval')
try:
    executor.preprocess(notebook, {'metadata': {'path': str(ROOT)}}, km=manager)
finally:
    if manager.has_kernel:
        manager.shutdown_kernel(now=True)
    manager.cleanup_resources()
target = output / 'eta_eval.executed.ipynb'
nbformat.write(notebook, target)
for cell in notebook.cells:
    for result in cell.get('outputs', []):
        if result.get('output_type') == 'stream':
            print(result['text'], end='')
print(f'Executed notebook saved: {target}')
