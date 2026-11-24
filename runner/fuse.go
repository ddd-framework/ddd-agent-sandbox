package main

// Placeholder FUSE of the mounted drive spaces (README "Drive mode"): below
// /workspace/drivespaces/ every mounted space is a read-only folder that lists every file of the space from the
// metadata snapshot (names, sizes), serves the local ones (pinned folders, fetched files, small text files) from the
// root-owned cache, and answers ENODATA when a file that is not local is opened, recording the path for the hint of the
// running call.
//
// Without CAP_SYS_ADMIN (Pod Security baseline) the mount happens in a nested user namespace:
//   - at the first drive claim with mounts the runner starts a holder child with CLONE_NEWUSER|CLONE_NEWNS and writes
//     its maps: inner 0 → outer RUNNER_FUSE_HOLDER_UID (100000; outer 0 cannot be mapped without CAP_SETFCAP) and the
//     whole session uid range onto itself. The child becomes root inside, opens /dev/fuse, mounts the file system once
//     at RUNNER_FUSE_ROOT (allow_other, read-only, nosuid, nodev) and hands the /dev/fuse descriptor back;
//   - the runner serves it with go-fuse (the "/dev/fd/N" mount point form) for its whole life: one connection, whose
//     readers never have to be woken (under gVisor neither close, unmount nor the end of the namespace wakes a thread
//     blocked in read on /dev/fuse; a connection per session leaked about three threads per session). The file data
//     never leaves the root-only cache; the snapshot changes in place;
//   - per session the holder binds the file system's root onto /workspace/drivespaces in its namespace (and removes
//     the bind at release); the root lists the session's mounts;
//   - session shells and jobs start inside that user and mount namespace through nsenter (Go cannot setns into a user
//     namespace from a multi-threaded process) and run as the session uid there; the helper then installs the seccomp
//     filter. Every other process (the runner, the helpers outside) sees the plain, empty folder.
// When the holder or the mount fails (no /dev/fuse, no user namespaces), the runner logs it once and falls back to
// Plan B: pinned folders and small text files as read-only copies in the tree.

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"hash/fnv"
	"io"
	"log"
	"os"
	"os/exec"
	"path"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/hanwen/go-fuse/v2/fs"
	"github.com/hanwen/go-fuse/v2/fuse"
	"golang.org/x/sys/unix"
)

const (
	fuseHolderArgument = "__fuse-holder"
	// fuseMaxRead: max_read of the mount and MaxWrite of the server must agree
	fuseMaxRead = 128 << 10
	// fuseTimeout: entries and attributes the kernel may cache; a pull shows within this time
	fuseTimeout = time.Second
	// fuseMissLimit: misses recorded per call at most (the hint shows 10 and counts the rest)
	fuseMissLimit = 10000
	// fuseStreamedNote is appended to SPACE.md of a streamed mount
	fuseStreamedNote = "sync: streamed · every file of the space is listed here; opening one that is not local fails " +
		"with \"No data available\" (ENODATA): fetch it with `drive get <path>` or `drive pin <folder>`\n"
)

// ---------------------------------------------------------------------------------------------------------------------
// The holder (child side): `sandbox-runner __fuse-holder <root>`, root inside its own user and mount namespace. fd 3
// is the socket to the runner (answers), stdin carries the runner's commands ("bind <path>", "unbind <path>"); EOF ends
// the holder.

// RunFuseHolder mounts the file system, passes the /dev/fuse descriptor to the runner and then binds and unbinds the
// root onto session folders on request.
func RunFuseHolder(arguments []string) {
	socket := int(os.NewFile(3, "runner").Fd())
	answer := func(err error) {
		if err != nil {
			unix.Sendmsg(socket, []byte("err "+err.Error()), nil, nil, 0)
			return
		}
		unix.Sendmsg(socket, []byte("ok"), nil, nil, 0)
	}
	if len(arguments) != 1 {
		answer(fmt.Errorf("usage"))
		os.Exit(1)
	}
	root := arguments[0]
	descriptor, err := holderMount(root)
	if err != nil {
		answer(err)
		os.Exit(1)
	}
	if err := unix.Sendmsg(socket, []byte("ok"), unix.UnixRights(descriptor), nil, 0); err != nil {
		os.Exit(1)
	}
	unix.Close(descriptor)
	lines := bufio.NewScanner(os.Stdin)
	for lines.Scan() {
		verb, target, _ := strings.Cut(lines.Text(), " ")
		switch verb {
		case "bind":
			// read-only already: the bind shares the read-only FUSE superblock
			answer(unix.Mount(root, target, "", unix.MS_BIND, ""))
		case "unbind":
			answer(unix.Unmount(target, unix.MNT_DETACH))
		default:
			answer(fmt.Errorf("unknown command"))
		}
	}
	os.Exit(0)
}

func holderMount(mountPoint string) (int, error) {
	descriptor, err := unix.Open("/dev/fuse", unix.O_RDWR|unix.O_CLOEXEC, 0)
	if err != nil {
		return -1, fmt.Errorf("open /dev/fuse: %w", err)
	}
	data := fmt.Sprintf("fd=%d,rootmode=40000,user_id=0,group_id=0,max_read=%d,allow_other,default_permissions",
		descriptor, fuseMaxRead)
	if err := unix.Mount("drive", mountPoint, "fuse", unix.MS_RDONLY|unix.MS_NOSUID|unix.MS_NODEV, data); err != nil {
		unix.Close(descriptor)
		return -1, fmt.Errorf("mount: %w", err)
	}
	return descriptor, nil
}

// ---------------------------------------------------------------------------------------------------------------------
// The runner side

// fuseService is the placeholder file system of the runner: the holder, its namespaces and the one FUSE connection.
type fuseService struct {
	runner      *Runner
	root        string
	holderPID   int
	holderStdin *os.File
	socket      *os.File
	exited      chan struct{}
	device      *os.File
	server      *fuse.Server
	// commands to the holder run one at a time
	commands sync.Mutex
	// session: the session the mounts belong to (nil between sessions); views: its snapshot per mount name, replaced
	// as a whole at every sync
	session atomic.Pointer[Session]
	views   atomic.Pointer[map[string]*fuseView]
	// started: the time every placeholder shows as its modification time (the snapshot carries no dates)
	started time.Time
}

// fuseView is the immutable snapshot of one mount that the file system serves.
type fuseView struct {
	name     string
	files    map[string]fuseFileView
	children map[string][]fuseChild
	spaceMD  []byte
	index    []byte
	cache    func(sha string) string
}

type fuseFileView struct {
	size  int64
	sha   string
	local bool
}

type fuseChild struct {
	name  string
	isDir bool
}

// fuseAvailable: the placeholder file system is tried (root runner, RUNNER_DRIVE_FUSE not 0).
func (runner *Runner) fuseAvailable() bool {
	return runner.privileged && runner.config.DriveFuse
}

// fuseService answers the running service, starting it (again) when needed.
func (runner *Runner) fuseService() (*fuseService, error) {
	runner.fuseMutex.Lock()
	defer runner.fuseMutex.Unlock()
	if runner.fuse != nil && runner.fuse.alive() {
		return runner.fuse, nil
	}
	service, err := runner.startFuse()
	if err != nil {
		return nil, err
	}
	runner.fuse = service
	return service, nil
}

// startFuse starts the holder, receives the mounted /dev/fuse descriptor and serves it.
func (runner *Runner) startFuse() (*fuseService, error) {
	config := runner.config
	root := config.FuseRoot
	if err := os.MkdirAll(root, 0o755); err != nil {
		return nil, fmt.Errorf("root: %w", err)
	}
	os.Chmod(root, 0o755)
	pair, err := unix.Socketpair(unix.AF_UNIX, unix.SOCK_SEQPACKET|unix.SOCK_CLOEXEC, 0)
	if err != nil {
		return nil, err
	}
	parent := os.NewFile(uintptr(pair[0]), "fuse-holder")
	child := os.NewFile(uintptr(pair[1]), "fuse-runner")
	stdinReader, stdinWriter, err := os.Pipe()
	if err != nil {
		parent.Close()
		child.Close()
		return nil, err
	}
	command := exec.Command(runner.executable, fuseHolderArgument, root)
	command.Env = []string{"PATH=" + config.Path}
	command.Stdin = stdinReader
	command.ExtraFiles = []*os.File{child}
	// inner 0 is the holder; the whole session uid range maps onto itself, so every session's uid exists inside
	mapping := []syscall.SysProcIDMap{{ContainerID: 0, HostID: config.FuseHolderUID, Size: 1},
		{ContainerID: config.UIDBase, HostID: config.UIDBase, Size: config.UIDSpan}}
	command.SysProcAttr = &syscall.SysProcAttr{
		Cloneflags:                 syscall.CLONE_NEWUSER | syscall.CLONE_NEWNS,
		UidMappings:                mapping,
		GidMappings:                mapping,
		GidMappingsEnableSetgroups: true,
		Credential:                 &syscall.Credential{Uid: 0, Gid: 0, Groups: []uint32{}},
		// no Pdeathsig: Go sends it when the starting thread ends, not the runner; a runner that ends takes the
		// container (tini) with it
	}
	startErr := command.Start()
	stdinReader.Close()
	child.Close()
	if startErr != nil {
		parent.Close()
		stdinWriter.Close()
		return nil, fmt.Errorf("holder: %w", startErr)
	}
	service := &fuseService{runner: runner, root: root, holderPID: command.Process.Pid, holderStdin: stdinWriter,
		socket: parent, exited: make(chan struct{}), started: time.Now()}
	go func() {
		command.Wait()
		close(service.exited)
	}()
	empty := map[string]*fuseView{}
	service.views.Store(&empty)
	device, err := receiveAnswer(parent, true)
	if err != nil {
		service.stop()
		return nil, err
	}
	service.device = device
	timeout := fuseTimeout
	server, err := fuse.NewServer(fs.NewNodeFS(&fuseRoot{service: service}, &fs.Options{
		// no NegativeTimeout: go-fuse answers a cached miss as an entry with node id 0, which gVisor turns into EIO
		EntryTimeout: &timeout, AttrTimeout: &timeout,
	}), "/dev/fd/"+strconv.Itoa(int(device.Fd())), &fuse.MountOptions{
		MaxWrite: fuseMaxRead, DisableXAttrs: true, Name: "drive", FsName: "drive",
		Logger: log.New(io.Discard, "", 0),
	})
	if err != nil {
		service.stop()
		return nil, fmt.Errorf("serve: %w", err)
	}
	service.server = server
	go server.Serve()
	if err := server.WaitMount(); err != nil {
		service.stop()
		return nil, fmt.Errorf("init: %w", err)
	}
	return service, nil
}

// receiveAnswer reads one answer of the holder: "ok" (with a descriptor when withDescriptor) or "err <reason>".
func receiveAnswer(socket *os.File, withDescriptor bool) (*os.File, error) {
	socket.SetReadDeadline(time.Now().Add(10 * time.Second))
	raw, err := socket.SyscallConn()
	if err != nil {
		return nil, err
	}
	buffer := make([]byte, 512)
	control := make([]byte, unix.CmsgSpace(4))
	var count, controlCount int
	var receiveErr error
	readErr := raw.Read(func(descriptor uintptr) bool {
		count, controlCount, _, _, receiveErr = unix.Recvmsg(int(descriptor), buffer, control, unix.MSG_CMSG_CLOEXEC)
		return receiveErr != unix.EAGAIN
	})
	if readErr != nil {
		return nil, readErr
	}
	if receiveErr != nil {
		return nil, receiveErr
	}
	if count == 0 {
		return nil, fmt.Errorf("the holder ended")
	}
	if message := string(buffer[:count]); message != "ok" {
		return nil, errors.New(strings.TrimPrefix(message, "err "))
	}
	if !withDescriptor {
		return nil, nil
	}
	messages, err := unix.ParseSocketControlMessage(control[:controlCount])
	if err != nil || len(messages) != 1 {
		return nil, fmt.Errorf("no descriptor")
	}
	descriptors, err := unix.ParseUnixRights(&messages[0])
	if err != nil || len(descriptors) != 1 {
		return nil, fmt.Errorf("no descriptor")
	}
	return os.NewFile(uintptr(descriptors[0]), "fuse"), nil
}

// command sends one command to the holder and waits for its answer.
func (service *fuseService) command(verb, target string) error {
	service.commands.Lock()
	defer service.commands.Unlock()
	if !service.alive() {
		return fmt.Errorf("the holder ended")
	}
	if _, err := service.holderStdin.Write([]byte(verb + " " + target + "\n")); err != nil {
		return err
	}
	_, err := receiveAnswer(service.socket, false)
	return err
}

// stop ends a holder that failed to start (a running service lives as long as the runner).
func (service *fuseService) stop() {
	service.holderStdin.Close()
	select {
	case <-service.exited:
	case <-time.After(2 * time.Second):
		syscall.Kill(service.holderPID, syscall.SIGKILL)
	}
	service.socket.Close()
}

// alive reports whether the holder still runs (its namespaces can be joined).
func (service *fuseService) alive() bool {
	select {
	case <-service.exited:
		return false
	default:
		return true
	}
}

// attach binds the file system onto the session's drivespaces folder and serves the session's mounts.
func (service *fuseService) attach(session *Session, state *DriveState) error {
	service.session.Store(session)
	service.publish(state)
	if err := service.command("bind", path.Join(session.runner.config.Workspace, DriveSpacesDir)); err != nil {
		service.detach(session)
		return fmt.Errorf("bind: %w", err)
	}
	return nil
}

// detach removes the bind of the session (no process of it is left) and empties the file system.
func (service *fuseService) detach(session *Session) error {
	if !service.session.CompareAndSwap(session, nil) {
		return nil
	}
	empty := map[string]*fuseView{}
	service.views.Store(&empty)
	return service.command("unbind", path.Join(session.runner.config.Workspace, DriveSpacesDir))
}

// namespaceArguments: the nsenter prefix that starts a command inside the holder's namespaces as uid.
func (service *fuseService) namespaceArguments(uid int) []string {
	holder := "/proc/" + strconv.Itoa(service.holderPID) + "/ns/"
	id := strconv.Itoa(uid)
	return []string{"--user=" + holder + "user", "--mount=" + holder + "mnt", "--setuid=" + id, "--setgid=" + id, "--"}
}

// publish replaces the snapshot of every mount (the caller holds the state mutex).
func (service *fuseService) publish(state *DriveState) {
	views := map[string]*fuseView{}
	for _, name := range state.mountNames() {
		mount := state.Mounts[name]
		view := &fuseView{name: name, files: map[string]fuseFileView{}, children: map[string][]fuseChild{},
			spaceMD: append([]byte(mount.SpaceMD), []byte("\n"+fuseStreamedNote)...), index: indexLines(mount),
			cache: state.cachePath}
		// directories: folder → child name → is a folder ("" is the mount root)
		directories := map[string]map[string]bool{"": {}}
		for entryPath, entry := range mount.Entries {
			if entry.LinkTarget != "" {
				continue
			}
			view.files[entryPath] = fuseFileView{size: entry.Size, sha: contentOf(entry), local: entry.Local}
			child, isDir := entryPath, false
			for {
				parent := path.Dir(child)
				if parent == "." {
					parent = ""
				}
				if directories[parent] == nil {
					directories[parent] = map[string]bool{}
				}
				_, known := directories[parent][path.Base(child)]
				directories[parent][path.Base(child)] = isDir || directories[parent][path.Base(child)]
				if parent == "" || known && isDir {
					break
				}
				child, isDir = parent, true
			}
		}
		for directory, names := range directories {
			children := make([]fuseChild, 0, len(names))
			for childName, isDir := range names {
				children = append(children, fuseChild{name: childName, isDir: isDir})
			}
			sort.Slice(children, func(left, right int) bool { return children[left].name < children[right].name })
			view.children[directory] = children
		}
		views[name] = view
	}
	service.views.Store(&views)
}

func (service *fuseService) view(name string) *fuseView {
	return (*service.views.Load())[name]
}

// recordMiss files a miss under the call of the attached session.
func (service *fuseService) recordMiss(workspacePath string, pid int) {
	if session := service.session.Load(); session != nil {
		session.recordMiss(workspacePath, pid)
	}
}

// fuseInode: a stable inode per path and content, so a new version is a new file to the kernel (no stale page cache).
func fuseInode(parts ...string) uint64 {
	hash := fnv.New64a()
	for _, part := range parts {
		hash.Write([]byte(part))
		hash.Write([]byte{0})
	}
	return hash.Sum64() | 1<<63
}

// ---------------------------------------------------------------------------------------------------------------------
// The root: one folder per mount of the attached session

type fuseRoot struct {
	fs.Inode
	service *fuseService
}

var (
	_ fs.NodeLookuper  = (*fuseRoot)(nil)
	_ fs.NodeReaddirer = (*fuseRoot)(nil)
	_ fs.NodeGetattrer = (*fuseRoot)(nil)
)

func (root *fuseRoot) Getattr(_ context.Context, _ fs.FileHandle, out *fuse.AttrOut) syscall.Errno {
	out.Mode = syscall.S_IFDIR | 0o555
	out.Nlink = 2
	out.SetTimes(nil, &root.service.started, &root.service.started)
	return fs.OK
}

func (root *fuseRoot) Lookup(ctx context.Context, name string, out *fuse.EntryOut) (*fs.Inode, syscall.Errno) {
	if root.service.view(name) == nil {
		return nil, syscall.ENOENT
	}
	node := &fuseDir{service: root.service, mount: name}
	node.attributes(&out.Attr)
	return root.NewInode(ctx, node, fs.StableAttr{Mode: syscall.S_IFDIR, Ino: fuseInode(name, "", "/")}), fs.OK
}

func (root *fuseRoot) Readdir(_ context.Context) (fs.DirStream, syscall.Errno) {
	views := *root.service.views.Load()
	names := make([]string, 0, len(views))
	for name := range views {
		names = append(names, name)
	}
	sort.Strings(names)
	entries := make([]fuse.DirEntry, 0, len(names))
	for _, name := range names {
		entries = append(entries, fuse.DirEntry{Name: name, Mode: syscall.S_IFDIR, Ino: fuseInode(name, "", "/")})
	}
	return fs.NewListDirStream(entries), fs.OK
}

// ---------------------------------------------------------------------------------------------------------------------
// Nodes

type fuseDir struct {
	fs.Inode
	service *fuseService
	mount   string
	// relative: the folder inside the mount ("" for the root)
	relative string
}

var (
	_ fs.NodeLookuper  = (*fuseDir)(nil)
	_ fs.NodeReaddirer = (*fuseDir)(nil)
	_ fs.NodeGetattrer = (*fuseDir)(nil)
	_ fs.NodeStatfser  = (*fuseDir)(nil)
)

func (directory *fuseDir) attributes(out *fuse.Attr) {
	out.Mode = syscall.S_IFDIR | 0o555
	out.Nlink = 2
	out.SetTimes(nil, &directory.service.started, &directory.service.started)
}

func (directory *fuseDir) Getattr(_ context.Context, _ fs.FileHandle, out *fuse.AttrOut) syscall.Errno {
	directory.attributes(&out.Attr)
	return fs.OK
}

func (directory *fuseDir) Statfs(_ context.Context, out *fuse.StatfsOut) syscall.Errno {
	out.Bsize, out.NameLen = 4096, 255
	return fs.OK
}

// virtual answers the generated files at the mount root.
func (directory *fuseDir) virtual(view *fuseView, name string) ([]byte, bool) {
	if directory.relative != "" || view == nil {
		return nil, false
	}
	switch name {
	case DriveSpaceFile:
		return view.spaceMD, true
	case DriveIndexFile:
		return view.index, true
	}
	return nil, false
}

func (directory *fuseDir) Lookup(ctx context.Context, name string, out *fuse.EntryOut) (*fs.Inode, syscall.Errno) {
	view := directory.service.view(directory.mount)
	if view == nil {
		return nil, syscall.ENOENT
	}
	if _, ok := directory.virtual(view, name); ok {
		node := &fuseVirtual{service: directory.service, mount: directory.mount, name: name}
		node.attributes(view, &out.Attr)
		return directory.NewInode(ctx, node, fs.StableAttr{Mode: syscall.S_IFREG, Ino: fuseInode(directory.mount, "virtual", name)}), fs.OK
	}
	full := path.Join(directory.relative, name)
	if file, ok := view.files[full]; ok {
		node := &fuseFile{service: directory.service, mount: directory.mount, relative: full, view: file}
		node.attributes(&out.Attr)
		return directory.NewInode(ctx, node, fs.StableAttr{Mode: syscall.S_IFREG, Ino: fuseInode(directory.mount, full, file.sha)}), fs.OK
	}
	if _, ok := view.children[full]; ok {
		node := &fuseDir{service: directory.service, mount: directory.mount, relative: full}
		node.attributes(&out.Attr)
		return directory.NewInode(ctx, node, fs.StableAttr{Mode: syscall.S_IFDIR, Ino: fuseInode(directory.mount, full, "/")}), fs.OK
	}
	return nil, syscall.ENOENT
}

func (directory *fuseDir) Readdir(_ context.Context) (fs.DirStream, syscall.Errno) {
	view := directory.service.view(directory.mount)
	if view == nil {
		return fs.NewListDirStream(nil), fs.OK
	}
	var entries []fuse.DirEntry
	if directory.relative == "" {
		for _, name := range []string{DriveIndexFile, DriveSpaceFile} {
			entries = append(entries, fuse.DirEntry{Name: name, Mode: syscall.S_IFREG, Ino: fuseInode(directory.mount, "virtual", name)})
		}
	}
	for _, child := range view.children[directory.relative] {
		full := path.Join(directory.relative, child.name)
		if child.isDir {
			entries = append(entries, fuse.DirEntry{Name: child.name, Mode: syscall.S_IFDIR, Ino: fuseInode(directory.mount, full, "/")})
		} else if file, ok := view.files[full]; ok {
			entries = append(entries, fuse.DirEntry{Name: child.name, Mode: syscall.S_IFREG, Ino: fuseInode(directory.mount, full, file.sha)})
		}
	}
	return fs.NewListDirStream(entries), fs.OK
}

// fuseFile is one file of the space: local (served from the cache) or a placeholder (ENODATA on open).
type fuseFile struct {
	fs.Inode
	service  *fuseService
	mount    string
	relative string
	view     fuseFileView
}

var (
	_ fs.NodeOpener    = (*fuseFile)(nil)
	_ fs.NodeGetattrer = (*fuseFile)(nil)
)

func (file *fuseFile) attributes(out *fuse.Attr) {
	out.Mode = syscall.S_IFREG | 0o444
	out.Nlink = 1
	out.Size = uint64(file.view.size)
	out.Blocks = (out.Size + 511) / 512
	out.Blksize = 4096
	out.SetTimes(nil, &file.service.started, &file.service.started)
}

func (file *fuseFile) Getattr(_ context.Context, _ fs.FileHandle, out *fuse.AttrOut) syscall.Errno {
	// the inode carries one version (its number includes the hash); a newer snapshot shows through a new lookup
	file.attributes(&out.Attr)
	return fs.OK
}

func (file *fuseFile) Open(ctx context.Context, flags uint32) (fs.FileHandle, uint32, syscall.Errno) {
	if flags&(syscall.O_WRONLY|syscall.O_RDWR|syscall.O_TRUNC|syscall.O_APPEND) != 0 {
		return nil, 0, syscall.EROFS
	}
	// the latest snapshot decides: a file fetched since the lookup is local now
	current := file.view
	if view := file.service.view(file.mount); view != nil {
		if latest, ok := view.files[file.relative]; ok && latest.sha == file.view.sha {
			current = latest
		}
		if current.local {
			// a raw descriptor: the loopback handle owns and closes it
			if descriptor, err := syscall.Open(view.cache(current.sha), syscall.O_RDONLY|syscall.O_CLOEXEC, 0); err == nil {
				return fs.NewLoopbackFile(descriptor), fuse.FOPEN_KEEP_CACHE, fs.OK
			}
		}
	}
	pid := 0
	if caller, ok := fuse.FromContext(ctx); ok {
		pid = int(caller.Pid)
	}
	file.service.recordMiss(path.Join(DriveSpacesDir, file.mount, file.relative), pid)
	return nil, 0, syscall.ENODATA
}

// fuseVirtual is SPACE.md or .drive-index at the mount root: generated, read from the current snapshot.
type fuseVirtual struct {
	fs.Inode
	service *fuseService
	mount   string
	name    string
}

var (
	_ fs.NodeOpener    = (*fuseVirtual)(nil)
	_ fs.NodeGetattrer = (*fuseVirtual)(nil)
	_ fs.NodeReader    = (*fuseVirtual)(nil)
)

func (virtual *fuseVirtual) content() []byte {
	view := virtual.service.view(virtual.mount)
	if view == nil {
		return nil
	}
	if virtual.name == DriveSpaceFile {
		return view.spaceMD
	}
	return view.index
}

func (virtual *fuseVirtual) attributes(view *fuseView, out *fuse.Attr) {
	out.Mode = syscall.S_IFREG | 0o444
	out.Nlink = 1
	out.Size = uint64(len(virtual.content()))
	out.SetTimes(nil, &virtual.service.started, &virtual.service.started)
}

func (virtual *fuseVirtual) Getattr(_ context.Context, _ fs.FileHandle, out *fuse.AttrOut) syscall.Errno {
	virtual.attributes(nil, &out.Attr)
	return fs.OK
}

func (virtual *fuseVirtual) Open(_ context.Context, flags uint32) (fs.FileHandle, uint32, syscall.Errno) {
	if flags&(syscall.O_WRONLY|syscall.O_RDWR|syscall.O_TRUNC|syscall.O_APPEND) != 0 {
		return nil, 0, syscall.EROFS
	}
	// direct I/O: the content changes at every sync, the size the kernel cached may be old
	return nil, fuse.FOPEN_DIRECT_IO, fs.OK
}

func (virtual *fuseVirtual) Read(_ context.Context, _ fs.FileHandle, destination []byte, offset int64) (fuse.ReadResult, syscall.Errno) {
	content := virtual.content()
	if offset >= int64(len(content)) {
		return fuse.ReadResultData(nil), fs.OK
	}
	end := min(int64(len(content)), offset+int64(len(destination)))
	return fuse.ReadResultData(bytes.Clone(content[offset:end])), fs.OK
}

// ---------------------------------------------------------------------------------------------------------------------
// Misses: opens of not-local files, per call

// callMisses collects the misses of one exec or job.
type callMisses struct {
	mutex sync.Mutex
	paths []string
	seen  map[string]bool
}

func (misses *callMisses) add(workspacePath string) {
	misses.mutex.Lock()
	defer misses.mutex.Unlock()
	if misses.seen == nil {
		misses.seen = map[string]bool{}
	}
	if misses.seen[workspacePath] || len(misses.paths) >= fuseMissLimit {
		return
	}
	misses.seen[workspacePath] = true
	misses.paths = append(misses.paths, workspacePath)
}

func (misses *callMisses) list() []string {
	if misses == nil {
		return nil
	}
	misses.mutex.Lock()
	defer misses.mutex.Unlock()
	return append([]string(nil), misses.paths...)
}

// recordMiss files a miss under the foreground exec or job the opening process descends from.
func (session *Session) recordMiss(workspacePath string, pid int) {
	if pid <= 0 {
		return
	}
	run, job := session.callOfProcess(pid)
	switch {
	case run != nil:
		run.misses.add(workspacePath)
	case job != nil:
		job.misses.add(workspacePath)
	}
}

// callOfProcess finds the running foreground exec (by its shell) or the job a process descends from.
func (session *Session) callOfProcess(pid int) (*execRun, *Job) {
	session.mutex.Lock()
	shells := map[int]*execRun{}
	for _, run := range session.runs {
		select {
		case <-run.done:
		default:
			shells[run.shell.pid] = run
		}
	}
	jobs := map[int]*Job{}
	for _, job := range session.jobs {
		jobs[job.pid] = job
	}
	session.mutex.Unlock()
	for current, steps := threadGroupOf(pid), 0; current > 1 && steps < 256; current, steps = parentPID(current), steps+1 {
		if run := shells[current]; run != nil {
			return run, nil
		}
		if job := jobs[current]; job != nil {
			return nil, job
		}
	}
	return nil, nil
}

// threadGroupOf answers the process of a thread id: FUSE names the calling thread, and under gVisor a thread's
// /proc/<tid>/stat shows parent 0 (measured: rg's worker threads), so the walk starts at the thread group.
func threadGroupOf(tid int) int {
	data, err := os.ReadFile("/proc/" + strconv.Itoa(tid) + "/status")
	if err != nil {
		return tid
	}
	for _, line := range strings.Split(string(data), "\n") {
		if value, found := strings.CutPrefix(line, "Tgid:"); found {
			if tgid, err := strconv.Atoi(strings.TrimSpace(value)); err == nil && tgid > 0 {
				return tgid
			}
		}
	}
	return tid
}

// mountFilesystem decides how the session's mounts appear: the placeholder FUSE when it mounts, else Plan B (the
// reason goes to the state and, once per runner, to the log). The caller holds the state mutex.
func (session *Session) mountFilesystem(state *DriveState) {
	state.MountFilesystem = "plain"
	if len(state.Mounts) == 0 || !session.runner.fuseAvailable() {
		return
	}
	started := time.Now()
	service, err := session.runner.fuseService()
	if err == nil {
		err = service.attach(session, state)
	}
	if err != nil {
		state.MountFilesystem = "plain: the placeholder file system could not be mounted (" + err.Error() + ")"
		if session.runner.fuseFailures.Add(1) == 1 {
			session.runner.logger.Warn("placeholder FUSE not mounted; drive mounts fall back to Plan B",
				"session", session.ID, "reason", err.Error())
		}
		return
	}
	session.fuse = service
	state.Streamed = true
	state.MountFilesystem = "fuse"
	session.runner.logger.Info("placeholder FUSE attached", "session", session.ID, "mounts", len(state.Mounts),
		"holderPid", service.holderPID, "durationMs", time.Since(started).Milliseconds())
}

// detachFuse ends the session's placeholder mounts at release (no process of the session is left).
func (session *Session) detachFuse() {
	if session.fuse == nil {
		return
	}
	if err := session.fuse.detach(session); err != nil {
		session.runner.logger.Warn("placeholder FUSE bind not removed", "session", session.ID, "reason", err.Error())
	}
}
