"""Independent stdlib/Windows-API outer watchdog preparation, never auto-run.

Only two scopes: compile the reviewed C# source, or run its prepared fixture driver.
This does not import/use WindowsDiagnosticJob to certify its own containment.
Source review and a separately verified Python/PowerShell runtime are prerequisites.
"""
import argparse
import ctypes as c
from ctypes import wintypes as w
import json
import hashlib
import os
from pathlib import Path
import subprocess
import threading
import time

class SA(c.Structure):
    _fields_ = [('size', w.DWORD), ('descriptor', c.c_void_p), ('inherit', w.BOOL)]
class SI(c.Structure):
    _fields_ = [('size', w.DWORD), ('reserved', w.LPWSTR), ('desktop', w.LPWSTR), ('title', w.LPWSTR), *[(name, w.DWORD) for name in ('x','y','width','height','columns','rows','fill','flags')], ('show', w.WORD), ('reserved_size', w.WORD), ('reserved_bytes', c.c_void_p), ('stdin', w.HANDLE), ('stdout', w.HANDLE), ('stderr', w.HANDLE)]
class SIEX(c.Structure):
    _fields_ = [('startup', SI), ('attributes', c.c_void_p)]
class PI(c.Structure):
    _fields_ = [('process', w.HANDLE), ('thread', w.HANDLE), ('pid', w.DWORD), ('tid', w.DWORD)]
class Basic(c.Structure):
    _fields_ = [('process_time', c.c_longlong), ('job_time', c.c_longlong), ('flags', w.DWORD), ('minimum', c.c_size_t), ('maximum', c.c_size_t), ('active_limit', w.DWORD), ('affinity', c.c_size_t), ('priority', w.DWORD), ('scheduling', w.DWORD)]
class IO(c.Structure):
    _fields_ = [(name, c.c_ulonglong) for name in ('read_ops','write_ops','other_ops','read_bytes','write_bytes','other_bytes')]
class Extended(c.Structure):
    _fields_ = [('basic', Basic), ('io', IO), *[(name, c.c_size_t) for name in ('process_memory','job_memory','peak_process','peak_job')]]
class Accounting(c.Structure):
    _fields_ = [(name, c.c_longlong) for name in ('user','kernel','period_user','period_kernel')] + [(name, w.DWORD) for name in ('faults','total','active','terminated')]

def main():
    if os.name != 'nt':
        raise SystemExit('Windows only')
    parser = argparse.ArgumentParser()
    parser.add_argument('--phase', choices=['identity','compile','driver','sequence','php'], required=True)
    parser.add_argument('--powershell', required=True)
    parser.add_argument('--evidence', required=True)
    parser.add_argument('--compiled-evidence')
    parser.add_argument('--php')
    args = parser.parse_args()
    if args.phase != 'php' and args.php: raise SystemExit('PHP input only allowed for PHP phase')
    root = Path(__file__).resolve().parent.parent
    evidence = Path(args.evidence).resolve()
    evidence.mkdir(exist_ok=False)
    shell = str(Path(args.powershell).resolve(strict=True))
    source_path = root / 'tools' / 'WindowsDiagnosticJob.cs'
    source_hash = hashlib.sha256(source_path.read_bytes()).hexdigest()
    if args.phase == 'identity':
        if args.compiled_evidence: raise SystemExit('identity takes no compile evidence')
        command = [shell, '-NoProfile', '-File', str(root / 'tools/read-diagnostic-runtime-identity.ps1'), '-PythonPath', str(Path(__import__('sys').executable).resolve())]
        seconds = 10
    elif args.phase == 'compile':
        if args.compiled_evidence: raise SystemExit('compile takes no prior assembly')
        source = str(source_path).replace("'", "''")
        assembly = evidence / 'WindowsDiagnosticJob.dll'
        destination = str(assembly).replace("'", "''")
        command = [shell, '-NoProfile', '-Command', "$ErrorActionPreference='Stop'; Add-Type -Path '" + source + "' -OutputAssembly '" + destination + "' -OutputType Library"]
        seconds = 30
    elif args.phase in ('driver','php'):
        if not args.compiled_evidence: raise SystemExit('verified compile evidence required')
        prior = Path(args.compiled_evidence).resolve(strict=True)
        manifest_path = prior / 'assembly.json'
        if manifest_path.stat().st_size > 4096: raise SystemExit('oversized assembly manifest')
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
        outcome_path = prior / 'outer.json'
        if outcome_path.stat().st_size > 8192: raise SystemExit('oversized compile outcome')
        outcome = json.loads(outcome_path.read_text(encoding='utf-8'))
        assembly = prior / 'WindowsDiagnosticJob.dll'
        if assembly.stat().st_size > 4194304: raise SystemExit('oversized assembly')
        assembly_hash = hashlib.sha256(assembly.read_bytes()).hexdigest()
        if manifest != {'source_sha256':source_hash,'assembly_sha256':assembly_hash,'powershell':shell}: raise SystemExit('compile identity mismatch')
        if outcome.get('phase') != 'compile' or outcome.get('exit') != 0 or outcome.get('root_exit') != 0 or outcome.get('source_sha256') != source_hash or outcome.get('assembly_sha256') != assembly_hash or outcome.get('failure') is not None or outcome.get('timeout') is not False or outcome.get('cleanup_failures') != [] or outcome.get('unresolved_handle_closures') != 0 or any(outcome.get(key) is not True for key in ('root_reaped','tree_cleanup_verified','drainers_stopped')) or any(outcome.get(stream,{}).get('complete') is not True or outcome.get(stream,{}).get('failed') is not False for stream in ('stdout','stderr')):
            raise SystemExit('compile outcome unverified or mismatched')
        if args.phase == 'driver':
            command = [shell, '-NoProfile', '-File', str(root / 'tests/fixtures/review-diagnostic-job-regressions.ps1'), '-EvidenceDirectory', str(evidence / 'cases'), '-AssemblyPath', str(assembly), '-AssemblySha256', assembly_hash, '-SourceSha256', source_hash]
        else:
            if not args.php: raise SystemExit('reviewed PHP executable required')
            php = str(Path(args.php).resolve(strict=True))
            command = [shell, '-NoProfile', '-File', str(root / 'tools/run-diagnostic-php-stage.ps1'), '-PhpPath', php, '-EvidenceDirectory', str(evidence / 'php'), '-AssemblyPath', str(assembly), '-AssemblySha256', assembly_hash]
        seconds = 20
    else:
        if args.compiled_evidence: raise SystemExit('sequence creates its own compile evidence')
        command = [shell, '-NoProfile', '-File', str(root / 'tools/run-diagnostic-review-sequence.ps1'), '-PythonPath', str(Path(__import__('sys').executable).resolve()), '-PowerShellPath', shell, '-EvidenceDirectory', str(evidence / 'phases')]
        # Aggregate containment includes both nested phases; no extension on timeout.
        seconds = 51
    kernel = c.WinDLL('kernel32', use_last_error=True)
    signatures = {
        'CreateJobObjectW': (w.HANDLE, [c.c_void_p,w.LPCWSTR]),
        'SetInformationJobObject': (w.BOOL,[w.HANDLE,c.c_int,c.c_void_p,w.DWORD]),
        'QueryInformationJobObject': (w.BOOL,[w.HANDLE,c.c_int,c.c_void_p,w.DWORD,c.c_void_p]),
        'AssignProcessToJobObject': (w.BOOL,[w.HANDLE,w.HANDLE]),
        'TerminateJobObject': (w.BOOL,[w.HANDLE,w.UINT]),
        'TerminateProcess': (w.BOOL,[w.HANDLE,w.UINT]),
        'CreatePipe': (w.BOOL,[c.POINTER(w.HANDLE),c.POINTER(w.HANDLE),c.POINTER(SA),w.DWORD]),
        'SetHandleInformation': (w.BOOL,[w.HANDLE,w.DWORD,w.DWORD]),
        'CreateFileW': (w.HANDLE,[w.LPCWSTR,w.DWORD,w.DWORD,c.POINTER(SA),w.DWORD,w.DWORD,w.HANDLE]),
        'InitializeProcThreadAttributeList': (w.BOOL,[c.c_void_p,w.DWORD,w.DWORD,c.POINTER(c.c_size_t)]),
        'UpdateProcThreadAttribute': (w.BOOL,[c.c_void_p,w.DWORD,c.c_size_t,c.c_void_p,c.c_size_t,c.c_void_p,c.c_void_p]),
        'DeleteProcThreadAttributeList': (None,[c.c_void_p]),
        'CreateProcessW': (w.BOOL,[w.LPCWSTR,w.LPWSTR,c.c_void_p,c.c_void_p,w.BOOL,w.DWORD,c.c_void_p,w.LPCWSTR,c.POINTER(SIEX),c.POINTER(PI)]),
        'ResumeThread': (w.DWORD,[w.HANDLE]),
        'WaitForSingleObject': (w.DWORD,[w.HANDLE,w.DWORD]),
        'GetExitCodeProcess': (w.BOOL,[w.HANDLE,c.POINTER(w.DWORD)]),
        'PeekNamedPipe': (w.BOOL,[w.HANDLE,c.c_void_p,w.DWORD,c.c_void_p,c.POINTER(w.DWORD),c.c_void_p]),
        'ReadFile': (w.BOOL,[w.HANDLE,c.c_void_p,w.DWORD,c.POINTER(w.DWORD),c.c_void_p]),
        'CloseHandle': (w.BOOL,[w.HANDLE]),
    }
    for name,(result,parameters) in signatures.items():
        fn=getattr(kernel,name); fn.restype=result; fn.argtypes=parameters
    def checked(value, label):
        if not value: raise RuntimeError(label)
        return value
    handles=[]; job=None; pi=PI(); attributes=None; initialized=False; assigned=False; resumed=False
    stop=threading.Event(); drainers=[]; outputs=[]; read_handles=[]; output_lock=threading.Lock()
    report={'phase':args.phase,'source_sha256':source_hash,'deadline_seconds':seconds,'root_reaped':False,'tree_cleanup_verified':False,'failure':None,'timeout':False,'root_exit':None,'cleanup_failures':[]}
    failed_closures=set()
    def cleanup_failure(label):
        with output_lock:
            if label not in report['cleanup_failures']: report['cleanup_failures'].append(label)
    def close_owned(handle, label):
        # A failed close retains ownership and is never blindly replayed.
        with output_lock:
            if handle in failed_closures: return False
        if kernel.CloseHandle(handle): return True
        with output_lock: failed_closures.add(handle)
        cleanup_failure(label)
        return False
    def drain(handle, state):
        buffer=c.create_string_buffer(256)
        try:
            while not stop.is_set():
                available=w.DWORD()
                if not kernel.PeekNamedPipe(handle,None,0,None,c.byref(available),None):
                    state['complete']=c.get_last_error()==109
                    state['failed']=not state['complete']; break
                if not available.value: time.sleep(.01); continue
                count=w.DWORD()
                checked(kernel.ReadFile(handle,buffer,min(256,available.value),c.byref(count),None),'drain_read')
                if not count.value: state['complete']=True; break
                with output_lock:
                    keep=min(count.value,8192-len(state['data']))
                    state['data'].extend(buffer.raw[:keep]); state['truncated'] |= keep<count.value
        except Exception:
            state['failed']=True
        finally:
            if not close_owned(handle,'drain_close'): state['failed']=True; state['complete']=False
    writers=[]
    try:
        job=checked(kernel.CreateJobObjectW(None,None),'job_create')
        limits=Extended(); limits.basic.flags=0x2000
        checked(kernel.SetInformationJobObject(job,9,c.byref(limits),c.sizeof(limits)),'job_limits')
        sa=SA(c.sizeof(SA),None,True)
        stdin=kernel.CreateFileW('NUL',0x80000000,3,c.byref(sa),3,0,None)
        if stdin==c.c_void_p(-1).value: raise RuntimeError('stdin_create')
        handles.append(stdin)
        for _ in range(2):
            read=w.HANDLE(); write=w.HANDLE()
            checked(kernel.CreatePipe(c.byref(read),c.byref(write),c.byref(sa),0),'pipe_create')
            handles.extend([read.value,write.value]); writers.append(write.value)
            read_handles.append(read.value)
            checked(kernel.SetHandleInformation(read,1,0),'read_inheritance')
            state={'data':bytearray(),'complete':False,'truncated':False,'failed':False}
            outputs.append(state)
            thread=threading.Thread(target=drain,args=(read.value,state),daemon=True)
            drainers.append(thread)
        size=c.c_size_t()
        kernel.InitializeProcThreadAttributeList(None,1,0,c.byref(size))
        attributes=c.create_string_buffer(size.value)
        checked(kernel.InitializeProcThreadAttributeList(attributes,1,0,c.byref(size)),'attributes_init'); initialized=True
        intended=(w.HANDLE*3)(stdin,*writers)
        checked(kernel.UpdateProcThreadAttribute(attributes,0,0x20002,intended,c.sizeof(intended),None,None),'handle_list')
        startup=SIEX(); startup.startup.size=c.sizeof(SIEX); startup.startup.flags=0x100
        startup.startup.stdin=stdin; startup.startup.stdout=writers[0]; startup.startup.stderr=writers[1]
        startup.attributes=c.cast(attributes,c.c_void_p)
        checked(kernel.CreateProcessW(shell,c.create_unicode_buffer(subprocess.list2cmdline(command)),None,None,True,0x08080004,None,str(root),c.byref(startup),c.byref(pi)),'launch')
        report['pid']=pi.pid
        checked(kernel.AssignProcessToJobObject(job,pi.process),'assignment'); assigned=True
        for writer in writers:
            checked(close_owned(writer,'writer_close'),'writer_close')
            handles.remove(writer)
        writers=[]
        for index,thread in enumerate(drainers):
            # Ownership transfers to drainer only once its thread actually starts.
            thread.start(); handles.remove(read_handles[index])
        checked(kernel.ResumeThread(pi.thread)!=0xFFFFFFFF,'resume'); resumed=True
        deadline=time.monotonic()+seconds
        while kernel.WaitForSingleObject(pi.process,0)!=0 and time.monotonic()<deadline: time.sleep(.01)
        report['root_reaped']=kernel.WaitForSingleObject(pi.process,0)==0
        report['timeout']=not report['root_reaped']
        code=w.DWORD()
        if report['root_reaped'] and kernel.GetExitCodeProcess(pi.process,c.byref(code)): report['root_exit']=code.value
    except Exception as error:
        report['failure']=str(error) if isinstance(error,RuntimeError) else type(error).__name__
    finally:
        cleanup=time.monotonic()+1
        if assigned:
            accounting=Accounting()
            if not kernel.QueryInformationJobObject(job,1,c.byref(accounting),c.sizeof(accounting),None): cleanup_failure('cleanup_query')
            elif accounting.active:
                report['termination_requested']=bool(kernel.TerminateJobObject(job,124))
                if not report['termination_requested']: cleanup_failure('job_termination')
            while time.monotonic()<cleanup:
                if not kernel.QueryInformationJobObject(job,1,c.byref(accounting),c.sizeof(accounting),None): cleanup_failure('cleanup_query'); break
                if not accounting.active: report['tree_cleanup_verified']=True; break
                time.sleep(.01)
        elif pi.process:
            if not kernel.TerminateProcess(pi.process,124): cleanup_failure('root_termination')
            while kernel.WaitForSingleObject(pi.process,0)!=0 and time.monotonic()<cleanup: time.sleep(.01)
            report['tree_cleanup_verified']=not resumed and kernel.WaitForSingleObject(pi.process,0)==0
        if pi.process: report['root_reaped']=kernel.WaitForSingleObject(pi.process,0)==0
        for writer in writers:
            if writer in handles and close_owned(writer,'writer_close'): handles.remove(writer)
        for thread in drainers:
            if thread.ident is not None: thread.join(max(0,cleanup-time.monotonic()))
        stop.set()
        if initialized: kernel.DeleteProcThreadAttributeList(attributes)
        for handle in list(handles):
            if close_owned(handle,'general_close'): handles.remove(handle)
        if job and close_owned(job,'job_close'): job=None
        if pi.thread and close_owned(pi.thread,'thread_close'): pi.thread=None
        if pi.process and close_owned(pi.process,'process_close'): pi.process=None
    report['drainers_stopped']=all(thread.ident is None or not thread.is_alive() for thread in drainers)
    snapshots=[]
    for name,state in zip(['stdout','stderr'],outputs):
        with output_lock:
            snapshot={key:value for key,value in state.items() if key!='data'}
            retained=bytes(state['data'])
        (evidence/name).write_bytes(retained)
        report[name]=snapshot; snapshots.append(snapshot)
    with output_lock: report['unresolved_handle_closures']=len(failed_closures)
    if report['cleanup_failures'] or not report['drainers_stopped']:
        report['tree_cleanup_verified']=False
    success=report['failure'] is None and not report['cleanup_failures'] and not report['timeout'] and report['root_reaped'] and report['tree_cleanup_verified'] and report['drainers_stopped'] and len(snapshots)==2 and all(s['complete'] and not s['failed'] for s in snapshots)
    report['exit']=report['root_exit'] if success and report['root_exit'] is not None else 124
    if args.phase == 'compile' and report['exit'] == 0:
        try:
            if not assembly.is_file() or assembly.stat().st_size > 4194304: raise RuntimeError('assembly_missing_or_oversized')
            if hashlib.sha256(source_path.read_bytes()).hexdigest() != source_hash: raise RuntimeError('source_changed')
            assembly_hash = hashlib.sha256(assembly.read_bytes()).hexdigest()
            report['assembly_sha256']=assembly_hash
            (evidence/'assembly.json').write_text(json.dumps({'source_sha256':source_hash,'assembly_sha256':assembly_hash,'powershell':shell}),encoding='utf-8')
        except Exception:
            report['failure']='assembly_evidence_failed'; report['exit']=124
    (evidence/'outer.json').write_text(json.dumps(report),encoding='utf-8')
    return report['exit']

if __name__=='__main__':
    raise SystemExit(main())
