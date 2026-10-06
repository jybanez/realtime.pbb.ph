using System;
using System.IO;
using System.Text;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using System.Diagnostics;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;

// Source preparation only. Windows Job containment before ResumeThread prevents
// the root-exit/child-escape race of assigning a running Process to a Job.
public static class WindowsDiagnosticJob
{
    [StructLayout(LayoutKind.Sequential)] struct SA { public int size; public IntPtr descriptor; public int inherit; }
    [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)] struct SI {
        public int size; public string reserved, desktop, title; public uint x,y,xSize,ySize,xChars,yChars,fill,flags;
        public short show, reservedSize; public IntPtr reservedBytes, stdin, stdout, stderr;
    }
    [StructLayout(LayoutKind.Sequential)] struct PI { public IntPtr process, thread; public uint pid, tid; }
    [StructLayout(LayoutKind.Sequential)] struct SIEX { public SI startup; public IntPtr attributes; }
    [StructLayout(LayoutKind.Sequential)] struct BasicLimits {
        public long processTime, jobTime; public uint flags; public UIntPtr minWorking, maxWorking;
        public uint activeLimit; public UIntPtr affinity; public uint priority, scheduling;
    }
    [StructLayout(LayoutKind.Sequential)] struct IoCounters { public ulong readOps, writeOps, otherOps, readBytes, writeBytes, otherBytes; }
    [StructLayout(LayoutKind.Sequential)] struct ExtendedLimits { public BasicLimits basic; public IoCounters io; public UIntPtr processMemory, jobMemory, peakProcessMemory, peakJobMemory; }
    [StructLayout(LayoutKind.Sequential)] struct Accounting { public long user, kernel, periodUser, periodKernel; public uint faults, total, active, terminated; }
    [DllImport("kernel32.dll", SetLastError=true)] static extern IntPtr CreateJobObject(IntPtr attributes, string name);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool SetInformationJobObject(IntPtr job, int kind, ref ExtendedLimits data, uint size);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool QueryInformationJobObject(IntPtr job, int kind, out Accounting data, uint size, IntPtr length);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool TerminateJobObject(IntPtr job, uint code);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool TerminateProcess(IntPtr process, uint code);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool CreatePipe(out IntPtr read, out IntPtr write, ref SA attributes, uint size);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool SetHandleInformation(IntPtr handle, uint mask, uint flags);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern bool CreateProcess(string app, StringBuilder command, IntPtr pa, IntPtr ta, bool inherit, uint flags, IntPtr environment, string cwd, ref SIEX si, out PI pi);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool InitializeProcThreadAttributeList(IntPtr list, int count, uint flags, ref UIntPtr size);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool UpdateProcThreadAttribute(IntPtr list, uint flags, UIntPtr attribute, IntPtr value, UIntPtr size, IntPtr previous, IntPtr returned);
    [DllImport("kernel32.dll")] static extern void DeleteProcThreadAttributeList(IntPtr list);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool PeekNamedPipe(IntPtr pipe, IntPtr buffer, uint size, IntPtr read, out uint available, IntPtr left);
    [DllImport("kernel32.dll", SetLastError=true)] static extern uint ResumeThread(IntPtr thread);
    [DllImport("kernel32.dll")] static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
    [DllImport("kernel32.dll")] static extern bool GetExitCodeProcess(IntPtr process, out uint code);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll")] static extern IntPtr GetStdHandle(int kind);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern IntPtr CreateFile(string name, uint access, uint share, ref SA attributes, uint creation, uint flags, IntPtr template);

    public sealed class Output {
        readonly object gate = new object(); readonly StringBuilder retained = new StringBuilder();
        volatile bool stop;
        public bool Complete { get; private set; } public bool Truncated { get; private set; } public bool Failed { get; private set; }
        public string Text { get { lock(gate) { return retained.ToString(); } } }
        public void RequestStop() { stop=true; }
        public void Drain(IntPtr handle) {
            try {
                using(var stream = new FileStream(new SafeFileHandle(handle, true), FileAccess.Read, 1, false)) {
                    byte[] bytes=new byte[256]; char[] chunk=new char[512]; var decoder=Encoding.UTF8.GetDecoder();
                    while(!stop) {
                        uint available;
                        if(!PeekNamedPipe(handle,IntPtr.Zero,0,IntPtr.Zero,out available,IntPtr.Zero)) {
                            if(Marshal.GetLastWin32Error()==109) Complete=true; else Failed=true;
                            break;
                        }
                        if(available==0) { Thread.Sleep(10); continue; }
                        int read=stream.Read(bytes,0,(int)Math.Min(256u,available));
                        if(read==0) { Complete=true; break; }
                        int count=decoder.GetChars(bytes,0,read,chunk,0,false);
                        lock(gate) {
                            int keep = Math.Min(count, 8192-retained.Length);
                            retained.Append(chunk, 0, keep);
                            if(keep < count) Truncated = true;
                        }
                    }
                }
            } catch { Failed = true; }
        }
    }
    public sealed class Result {
        public uint Pid; public bool Timeout, RootReaped, Assigned, TreeCleanupVerified, TerminationRequested;
        public uint? RootExit; public string Failure; public Output Stdout = new Output(), Stderr = new Output();
        public int ExitCode { get { return Failure != null || Timeout || !TreeCleanupVerified || !RootReaped || !Stdout.Complete || !Stderr.Complete ? 124 : (int)(RootExit ?? 124); } }
    }
    static string Quote(string value) {
        var b = new StringBuilder("\""); int slashes=0;
        foreach(char c in value) {
            if(c=='\\') { slashes++; continue; }
            if(c=='\"') { b.Append('\\', slashes*2+1); b.Append(c); slashes=0; continue; }
            b.Append('\\', slashes); slashes=0; b.Append(c);
        }
        b.Append('\\', slashes*2); return b.Append('"').ToString();
    }
    public enum ReviewFault { None, Assignment }
    public static Result Run(string executable, string[] arguments, string cwd, int milliseconds, ReviewFault reviewFault=ReviewFault.None) {
        if(milliseconds < 1 || milliseconds > 30000) throw new ArgumentOutOfRangeException("milliseconds");
        var result = new Result(); IntPtr job=IntPtr.Zero, input=IntPtr.Zero, outRead=IntPtr.Zero, outWrite=IntPtr.Zero, errRead=IntPtr.Zero, errWrite=IntPtr.Zero, attributesList=IntPtr.Zero, allowedHandles=IntPtr.Zero;
        bool attributesInitialized=false, resumed=false; Stopwatch cleanup=null;
        PI process = new PI(); Task outTask=null, errTask=null;
        try {
            job=CreateJobObject(IntPtr.Zero, null); if(job==IntPtr.Zero) throw new IOException("job_create");
            var limits=new ExtendedLimits(); limits.basic.flags=0x2000; // KILL_ON_JOB_CLOSE, no breakaway permitted.
            if(!SetInformationJobObject(job,9,ref limits,(uint)Marshal.SizeOf<ExtendedLimits>())) throw new IOException("job_limits");
            var attributes=new SA { size=Marshal.SizeOf<SA>(), inherit=1 };
            input=CreateFile("NUL",0x80000000,3,ref attributes,3,0,IntPtr.Zero);
            if(input==new IntPtr(-1)) { input=IntPtr.Zero; throw new IOException("stdin_create"); }
            if(!CreatePipe(out outRead,out outWrite,ref attributes,0) || !CreatePipe(out errRead,out errWrite,ref attributes,0)) throw new IOException("pipe_create");
            if(!SetHandleInformation(outRead,1,0) || !SetHandleInformation(errRead,1,0)) throw new IOException("pipe_inheritance");
            UIntPtr attributesSize=UIntPtr.Zero;
            InitializeProcThreadAttributeList(IntPtr.Zero,1,0,ref attributesSize);
            attributesList=Marshal.AllocHGlobal(checked((int)attributesSize.ToUInt64()));
            if(!InitializeProcThreadAttributeList(attributesList,1,0,ref attributesSize)) throw new IOException("handle_attributes_init");
            attributesInitialized=true;
            allowedHandles=Marshal.AllocHGlobal(IntPtr.Size*3);
            Marshal.WriteIntPtr(allowedHandles,0,input); Marshal.WriteIntPtr(allowedHandles,IntPtr.Size,outWrite); Marshal.WriteIntPtr(allowedHandles,IntPtr.Size*2,errWrite);
            if(!UpdateProcThreadAttribute(attributesList,0,new UIntPtr(0x20002),allowedHandles,new UIntPtr((uint)(IntPtr.Size*3)),IntPtr.Zero,IntPtr.Zero)) throw new IOException("handle_allowlist");
            var startup=new SIEX { startup=new SI { size=Marshal.SizeOf<SIEX>(), flags=0x100, stdin=input, stdout=outWrite, stderr=errWrite }, attributes=attributesList };
            var command=new StringBuilder(Quote(executable)+" "+string.Join(" ",arguments.Select(Quote)));
            if(!CreateProcess(executable,command,IntPtr.Zero,IntPtr.Zero,true,0x08080004,IntPtr.Zero,cwd,ref startup,out process)) throw new IOException("process_create");
            result.Pid=process.pid;
            if(reviewFault==ReviewFault.Assignment) throw new IOException("review_assignment_failure");
            if(!AssignProcessToJobObject(job,process.process)) throw new IOException("job_assignment");
            result.Assigned=true;
            CloseHandle(outWrite); outWrite=IntPtr.Zero; CloseHandle(errWrite); errWrite=IntPtr.Zero;
            IntPtr outputHandle=outRead, errorHandle=errRead;
            outTask=Task.Run(()=>result.Stdout.Drain(outputHandle)); outRead=IntPtr.Zero;
            errTask=Task.Run(()=>result.Stderr.Drain(errorHandle)); errRead=IntPtr.Zero;
            if(ResumeThread(process.thread)==uint.MaxValue) throw new IOException("process_resume");
            resumed=true;
            var clock=Stopwatch.StartNew();
            while(WaitForSingleObject(process.process,0)!=0 && clock.ElapsedMilliseconds<milliseconds) Thread.Sleep(10);
            result.RootReaped=WaitForSingleObject(process.process,0)==0;
            result.Timeout=!result.RootReaped;
            uint exit; if(result.RootReaped && GetExitCodeProcess(process.process,out exit)) result.RootExit=exit;
            Accounting accounting;
            if(!QueryInformationJobObject(job,1,out accounting,(uint)Marshal.SizeOf<Accounting>(),IntPtr.Zero)) throw new IOException("job_query");
            // Includes surviving descendants even on natural root exit.
            if(accounting.active>0) {
                result.TerminationRequested=TerminateJobObject(job,124);
                if(!result.TerminationRequested) throw new IOException("job_termination");
            }
            cleanup=Stopwatch.StartNew();
            do {
                if(!QueryInformationJobObject(job,1,out accounting,(uint)Marshal.SizeOf<Accounting>(),IntPtr.Zero)) throw new IOException("job_cleanup_query");
                if(accounting.active==0) { result.TreeCleanupVerified=true; break; }
                Thread.Sleep(10);
            } while(cleanup.ElapsedMilliseconds<1000);
            result.RootReaped=WaitForSingleObject(process.process,0)==0;
            // Drainers continuously discard excess; wait only for remaining cleanup budget.
            int remaining=Math.Max(0,1000-(int)cleanup.ElapsedMilliseconds);
            Task.WaitAll(new[]{outTask,errTask},remaining);
        } catch(Exception e) {
            result.Failure=e is IOException && e.Message.IndexOf(' ')<0 ? e.Message : e.GetType().Name;
            if(cleanup==null) cleanup=Stopwatch.StartNew();
            if(result.Assigned && job!=IntPtr.Zero) {
                result.TerminationRequested=TerminateJobObject(job,124);
                Accounting accounting;
                while(cleanup.ElapsedMilliseconds<1000) {
                    if(!QueryInformationJobObject(job,1,out accounting,(uint)Marshal.SizeOf<Accounting>(),IntPtr.Zero)) break;
                    if(accounting.active==0) { result.TreeCleanupVerified=true; break; }
                    Thread.Sleep(10);
                }
            } else if(process.process!=IntPtr.Zero) {
                result.TerminationRequested=TerminateProcess(process.process,124);
                while(cleanup.ElapsedMilliseconds<1000 && WaitForSingleObject(process.process,0)!=0) Thread.Sleep(10);
                result.RootReaped=WaitForSingleObject(process.process,0)==0;
                result.TreeCleanupVerified=!resumed && result.RootReaped; // Never resumed => no descendants created.
            }
            if(outWrite!=IntPtr.Zero) { CloseHandle(outWrite); outWrite=IntPtr.Zero; }
            if(errWrite!=IntPtr.Zero) { CloseHandle(errWrite); errWrite=IntPtr.Zero; }
            result.RootReaped=process.process!=IntPtr.Zero && WaitForSingleObject(process.process,0)==0;
            var tasks=new[]{outTask,errTask}.Where(t=>t!=null).ToArray();
            if(tasks.Length>0) {
                try { Task.WaitAll(tasks,Math.Max(0,1000-(int)cleanup.ElapsedMilliseconds)); }
                catch { result.Failure="drainer_cleanup_failure"; }
            }
        } finally {
            result.Stdout.RequestStop(); result.Stderr.RequestStop();
            if(job!=IntPtr.Zero) CloseHandle(job);
            if(input!=IntPtr.Zero) CloseHandle(input);
            if(process.thread!=IntPtr.Zero) CloseHandle(process.thread);
            if(process.process!=IntPtr.Zero) CloseHandle(process.process);
            if(outWrite!=IntPtr.Zero) CloseHandle(outWrite); if(errWrite!=IntPtr.Zero) CloseHandle(errWrite);
            if(outRead!=IntPtr.Zero) CloseHandle(outRead); if(errRead!=IntPtr.Zero) CloseHandle(errRead);
            if(attributesInitialized) DeleteProcThreadAttributeList(attributesList);
            if(attributesList!=IntPtr.Zero) Marshal.FreeHGlobal(attributesList);
            if(allowedHandles!=IntPtr.Zero) Marshal.FreeHGlobal(allowedHandles);
        }
        return result;
    }
}
