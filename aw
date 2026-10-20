--[[
    ╔═══════════════════════════════════════════════════════════╗
    ║                                                           ║
    ║     ███╗   ██╗ ██████╗ ██╗   ██╗ █████╗                  ║
    ║     ████╗  ██║██╔═══██╗██║   ██║██╔══██╗                 ║
    ║     ██╔██╗ ██║██║   ██║██║   ██║███████║                 ║
    ║     ██║╚██╗██║██║   ██║╚██╗ ██╔╝██╔══██║                 ║
    ║     ██║ ╚████║╚██████╔╝ ╚████╔╝ ██║  ██║                 ║
    ║     ╚═╝  ╚═══╝ ╚═════╝   ╚═══╝  ╚═╝  ╚═╝                 ║
    ║                                                           ║
    ║           Rivals  |  Aimbot · Aimlock · Triggerbot        ║
    ║                                                           ║
    ╚═══════════════════════════════════════════════════════════╝

    Hotkeys:
      [RightShift]  - Toggle Menu
      [Mouse Right] - Aimbot (while held)
      [F1]          - Toggle ESP
      [F2]          - Toggle Aimbot
      [F3]          - Toggle Silent Aim (disabled)
      [F4]          - Toggle Aimlock
      [F5]          - Toggle Triggerbot
      [End]         - Unload Nova
--]]

-- ═══════════════════════════════════════════════════════════════
--  이전 인스턴스 정리
-- ═══════════════════════════════════════════════════════════════
if getgenv().Nova and getgenv().Nova.Unload then
    pcall(getgenv().Nova.Unload)
end

if not game:IsLoaded() then game.Loaded:Wait() end

-- ═══════════════════════════════════════════════════════════════
--  서비스
-- ═══════════════════════════════════════════════════════════════
local Players           = game:GetService("Players")
local RunService        = game:GetService("RunService")
local UserInputService  = game:GetService("UserInputService")
local TweenService      = game:GetService("TweenService")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local Workspace         = game:GetService("Workspace")

local LP     = Players.LocalPlayer
local Camera = Workspace.CurrentCamera

local gethui = gethui or function() return game:GetService("CoreGui") end

-- ═══════════════════════════════════════════════════════════════
--  Nova 상태
-- ═══════════════════════════════════════════════════════════════
local Nova = {
    Connections = {},
    Instances   = {},
    Destroyed   = false,
    Settings    = {
        Aimbot = {
            Enabled     = true,
            FOV         = 200,
            FOVStep     = 10,
            Smoothing   = 0.15,
            TargetPart  = "Head",
            WallCheck   = true,
            VisibleOnly = true,
            MaxDistance = 1500,
            IgnoreTeam  = true,
        },
        Aimlock = {
            Enabled     = false,
            Smoothing   = 0.08,
            LockPart    = "Head",
            MaxDistance = 1500,
        },
        Triggerbot = {
            Enabled     = false,
            Delay       = 0.02,
            FOV         = 40,
            WallCheck   = true,
        },
        ESP = {
            Enabled     = true,
            ShowTeam    = false,
            MaxDistance = 1000,
        },
        MenuKey = Enum.KeyCode.RightShift,
    },
}
getgenv().Nova = Nova

-- ═══════════════════════════════════════════════════════════════
--  유틸
-- ═══════════════════════════════════════════════════════════════
local function track(c) table.insert(Nova.Connections, c); return c end
local function keep(i)  table.insert(Nova.Instances, i);   return i end

-- ═══════════════════════════════════════════════════════════════
--  게임 헬퍼
-- ═══════════════════════════════════════════════════════════════
local Game = {}

function Game:IsVisible(part, ignoreList)
    if not part or not part.Parent then return false end
    local origin = Camera.CFrame.Position
    local target = part.Position
    local dir = target - origin
    if dir.Magnitude < 1 then return true end

    local params = RaycastParams.new()
    params.FilterType = Enum.RaycastFilterType.Exclude
    params.IgnoreWater = true
    local filter = {}
    if LP.Character then table.insert(filter, LP.Character) end
    if ignoreList then
        for _, v in ipairs(ignoreList) do table.insert(filter, v) end
    end
    params.FilterDescendantsInstances = filter

    local result = Workspace:Raycast(origin, dir, params)
    if not result then return true end
    return result.Instance:IsDescendantOf(part.Parent)
end

local function isSameTeam(plr)
    if not Nova.Settings.Aimbot.IgnoreTeam then return false end
    local myTeam = LP:GetAttribute("TeamID")
    local plrTeam = plr:GetAttribute("TeamID")
    return myTeam ~= nil and plrTeam ~= nil and myTeam == plrTeam
end

local function isSameEnvironment(plr)
    local myEnv = LP:GetAttribute("EnvironmentID")
    local plrEnv = plr:GetAttribute("EnvironmentID")
    if myEnv and plrEnv then return myEnv == plrEnv end
    return true
end

local function getPartOf(char, partName)
    if not char then return nil end
    if partName == "Body" then
        return char:FindFirstChild("HitboxBody")
            or char:FindFirstChild("UpperTorso")
            or char:FindFirstChild("LowerTorso")
    end
    return char:FindFirstChild("HitboxHead") or char:FindFirstChild("Head")
end

local function getScreenCenter()
    local vp = Camera.ViewportSize
    return Vector2.new(vp.X * 0.5, vp.Y * 0.5)
end

-- ═══════════════════════════════════════════════════════════════
--  AIM 상태
-- ═══════════════════════════════════════════════════════════════
local Aim = {
    FOVGui     = nil,
    FOVCircle  = nil,
    FOVStroke  = nil,
    Aiming     = false,
    Target     = nil,
    LastClick  = 0,
}

-- ═══════════════════════════════════════════════════════════════
--  FOV 원 (화면 정중앙, 반지름 = FOV)
-- ═══════════════════════════════════════════════════════════════
local function createFOVCircle()
    if Aim.FOVGui and Aim.FOVGui.Parent then return end

    Aim.FOVGui = Instance.new("ScreenGui")
    Aim.FOVGui.Name = "NovaFOV"
    Aim.FOVGui.IgnoreGuiInset = true
    Aim.FOVGui.ZIndexBehavior = Enum.ZIndexBehavior.Global
    Aim.FOVGui.ResetOnSpawn = false
    Aim.FOVGui.DisplayOrder = 50
    Aim.FOVGui.Parent = gethui()
    keep(Aim.FOVGui)

    Aim.FOVCircle = Instance.new("Frame")
    Aim.FOVCircle.Name = "FOVCircle"
    Aim.FOVCircle.AnchorPoint = Vector2.new(0.5, 0.5)
    Aim.FOVCircle.Position = UDim2.fromScale(0.5, 0.5)
    Aim.FOVCircle.Size = UDim2.fromOffset(400, 400)
    Aim.FOVCircle.BackgroundColor3 = Color3.fromRGB(255, 60, 60)
    Aim.FOVCircle.BackgroundTransparency = 0.92
    Aim.FOVCircle.BorderSizePixel = 0
    Aim.FOVCircle.Visible = false
    Aim.FOVCircle.ZIndex = 2
    Aim.FOVCircle.Parent = Aim.FOVGui
    keep(Aim.FOVCircle)

    local corner = Instance.new("UICorner")
    corner.CornerRadius = UDim.new(1, 0)
    corner.Parent = Aim.FOVCircle
    keep(corner)

    Aim.FOVStroke = Instance.new("UIStroke")
    Aim.FOVStroke.Thickness = 2
    Aim.FOVStroke.Color = Color3.fromRGB(255, 60, 60)
    Aim.FOVStroke.Transparency = 0.1
    Aim.FOVStroke.ApplyStrokeMode = Enum.ApplyStrokeMode.Border
    Aim.FOVStroke.Parent = Aim.FOVCircle
    keep(Aim.FOVStroke)
end

local function updateFOVCircle()
    createFOVCircle()
    if not Aim.FOVCircle or not Aim.FOVCircle.Parent then return end
    local radius = tonumber(Nova.Settings.Aimbot.FOV) or 200
    radius = math.max(radius, 10)
    Aim.FOVCircle.Size = UDim2.fromOffset(radius * 2, radius * 2)
    Aim.FOVCircle.Visible = Nova.Settings.Aimbot.Enabled or Nova.Settings.Aimlock.Enabled

    local color = Nova.Settings.Aimlock.Enabled
        and Color3.fromRGB(100, 255, 150)
        or  Color3.fromRGB(255, 60, 60)
    Aim.FOVStroke.Color = color
    Aim.FOVCircle.BackgroundColor3 = color
end

-- ═══════════════════════════════════════════════════════════════
--  타겟 탐색
-- ═══════════════════════════════════════════════════════════════
local function isTargetValid(plr, part, fovRadius)
    if not part or not part.Parent then return false, nil end
    if isSameTeam(plr) or not isSameEnvironment(plr) then return false, nil end

    local hum = plr.Character and plr.Character:FindFirstChildOfClass("Humanoid")
    if not hum or hum.Health <= 0 then return false, nil end

    local screen, onScreen = Camera:WorldToViewportPoint(part.Position)
    if not onScreen then return false, nil end

    local screenVec = Vector2.new(screen.X, screen.Y)
    local center = getScreenCenter()
    local dist = (screenVec - center).Magnitude
    if dist > fovRadius then return false, nil end

    if Nova.Settings.Aimbot.WallCheck and Nova.Settings.Aimbot.VisibleOnly then
        if not Game:IsVisible(part, { plr.Character }) then return false, nil end
    end
    return true, dist
end

local function findBestTarget(fovRadius, partName)
    local best, bestDist = nil, math.huge
    for _, plr in ipairs(Players:GetPlayers()) do
        if plr == LP or not plr.Character then continue end
        local part = getPartOf(plr.Character, partName)
        local valid, dist = isTargetValid(plr, part, fovRadius)
        if valid and dist < bestDist then
            bestDist = dist
            best = { Player = plr, Part = part, ScreenDist = dist }
        end
    end
    return best
end

-- ═══════════════════════════════════════════════════════════════
--  카메라 조준 (Smoothing)
-- ═══════════════════════════════════════════════════════════════
local function aimAt(targetPart, smoothing, dt)
    if not targetPart or not targetPart.Parent then return end
    local targetPos = targetPart.Position
    local origin = Camera.CFrame.Position
    local desired = CFrame.new(origin, targetPos)

    local s = math.clamp(smoothing, 0, 1)
    if s <= 0 then
        Camera.CFrame = desired
    else
        local alpha = 1 - math.pow(s, dt * 60)
        alpha = math.clamp(alpha, 0.05, 1)
        Camera.CFrame = Camera.CFrame:Lerp(desired, alpha)
    end
end

-- ═══════════════════════════════════════════════════════════════
--  에임봇 (우클릭 누르는 동안 FOV 내 적 조준)
-- ═══════════════════════════════════════════════════════════════
local function updateAimbot(dt)
    if not Nova.Settings.Aimbot.Enabled or not Aim.Aiming then return end
    local target = findBestTarget(Nova.Settings.Aimbot.FOV, Nova.Settings.Aimbot.TargetPart)
    if not target then return end
    aimAt(target.Part, Nova.Settings.Aimbot.Smoothing, dt)
end

-- ═══════════════════════════════════════════════════════════════
--  에임락 (FOV 내 적에게 자동 고정)
-- ═══════════════════════════════════════════════════════════════
local function updateAimlock(dt)
    if not Nova.Settings.Aimlock.Enabled then
        Aim.Target = nil
        return
    end

    local target = Aim.Target
    if target then
        local stillValid = isTargetValid(target.Player, target.Part, Nova.Settings.Aimbot.FOV)
        if not stillValid then Aim.Target = nil end
    end

    if not Aim.Target then
        Aim.Target = findBestTarget(Nova.Settings.Aimbot.FOV, Nova.Settings.Aimlock.LockPart)
    end

    if Aim.Target and Aim.Target.Part and Aim.Target.Part.Parent then
        aimAt(Aim.Target.Part, Nova.Settings.Aimlock.Smoothing, dt)
    end
end

-- ═══════════════════════════════════════════════════════════════
--  트리거봇 (마우스 위치의 적 자동 발사)
-- ═══════════════════════════════════════════════════════════════
local function updateTriggerbot()
    if not Nova.Settings.Triggerbot.Enabled then return end
    if not LP.Character then return end

    local hum = LP.Character:FindFirstChildOfClass("Humanoid")
    if not hum or hum.Health <= 0 then return end

    local now = tick()
    if now - Aim.LastClick < Nova.Settings.Triggerbot.Delay then return end

    local mouse = UserInputService:GetMouseLocation()
    local ray = Camera:ViewportPointToRay(mouse.X, mouse.Y)

    local params = RaycastParams.new()
    params.FilterType = Enum.RaycastFilterType.Exclude
    params.FilterDescendantsInstances = { LP.Character }
    params.IgnoreWater = true

    local result = Workspace:Raycast(ray.Origin, ray.Direction * 2000, params)
    if not result then return end

    local hitChar = result.Instance:FindFirstAncestorOfClass("Model")
    if not hitChar then return end
    local hitPlr = Players:GetPlayerFromCharacter(hitChar)
    if not hitPlr or hitPlr == LP then return end
    if isSameTeam(hitPlr) or not isSameEnvironment(hitPlr) then return end

    local hitHum = hitChar:FindFirstChildOfClass("Humanoid")
    if not hitHum or hitHum.Health <= 0 then return end

    if Nova.Settings.Triggerbot.WallCheck then
        if not Game:IsVisible(result.Instance, { hitChar }) then return end
    end

    Aim.LastClick = now
    local vim = game:GetService("VirtualInputManager")
    if vim then
        pcall(function()
            vim:SendMouseButtonEvent(mouse.X, mouse.Y, 0, true, game, 0)
            task.wait(0.005)
            vim:SendMouseButtonEvent(mouse.X, mouse.Y, 0, false, game, 0)
        end)
    end
end

-- ═══════════════════════════════════════════════════════════════
--  알림 시스템
-- ═══════════════════════════════════════════════════════════════
local notifGui = keep(Instance.new("ScreenGui"))
notifGui.Name = "NovaNotify"
notifGui.IgnoreGuiInset = true
notifGui.ResetOnSpawn = false
notifGui.DisplayOrder = 200
notifGui.Parent = gethui()

local notifFrame = keep(Instance.new("Frame"))
notifFrame.AnchorPoint = Vector2.new(1, 0)
notifFrame.Position = UDim2.new(1, -20, 0, 20)
notifFrame.Size = UDim2.fromOffset(280, 400)
notifFrame.BackgroundTransparency = 1
notifFrame.Parent = notifGui

local notifLayout = keep(Instance.new("UIListLayout"))
notifLayout.Padding = UDim.new(0, 6)
notifLayout.SortOrder = Enum.SortOrder.LayoutOrder
notifLayout.HorizontalAlignment = Enum.HorizontalAlignment.Right
notifLayout.Parent = notifFrame

local function notify(text)
    local label = keep(Instance.new("TextLabel"))
    label.Size = UDim2.fromOffset(240, 32)
    label.BackgroundColor3 = Color3.fromRGB(20, 20, 20)
    label.BackgroundTransparency = 0.1
    label.TextColor3 = Color3.fromRGB(255, 100, 100)
    label.Font = Enum.Font.GothamBold
    label.TextSize = 13
    label.Text = "  [Nova] " .. tostring(text)
    label.TextXAlignment = Enum.TextXAlignment.Left
    label.Parent = notifFrame

    local corner = keep(Instance.new("UICorner"))
    corner.CornerRadius = UDim.new(0, 6)
    corner.Parent = label

    local stroke = keep(Instance.new("UIStroke"))
    stroke.Color = Color3.fromRGB(255, 60, 60)
    stroke.Transparency = 0.5
    stroke.Parent = label

    task.delay(3, function()
        if label.Parent then
            local tween = TweenService:Create(label, TweenInfo.new(0.3), {
                BackgroundTransparency = 1, TextTransparency = 1,
            })
            tween:Play()
            tween.Completed:Wait()
            label:Destroy()
        end
    end)
end

-- ═══════════════════════════════════════════════════════════════
--  메뉴 UI
-- ═══════════════════════════════════════════════════════════════
local Menu = {}
Menu.__index = Menu

function Menu.new()
    local self = setmetatable({}, Menu)
    self.Visible = true

    self.Gui = keep(Instance.new("ScreenGui"))
    self.Gui.Name = "NovaMenu"
    self.Gui.IgnoreGuiInset = true
    self.Gui.ResetOnSpawn = false
    self.Gui.DisplayOrder = 300
    self.Gui.Parent = gethui()

    self.Frame = keep(Instance.new("Frame"))
    self.Frame.AnchorPoint = Vector2.new(0, 0)
    self.Frame.Position = UDim2.fromOffset(40, 100)
    self.Frame.Size = UDim2.fromOffset(280, 460)
    self.Frame.BackgroundColor3 = Color3.fromRGB(15, 15, 18)
    self.Frame.BorderSizePixel = 0
    self.Frame.Parent = self.Gui

    local corner = keep(Instance.new("UICorner"))
    corner.CornerRadius = UDim.new(0, 8)
    corner.Parent = self.Frame

    local stroke = keep(Instance.new("UIStroke"))
    stroke.Color = Color3.fromRGB(255, 60, 60)
    stroke.Transparency = 0.3
    stroke.Parent = self.Frame

    local title = keep(Instance.new("TextLabel"))
    title.Size = UDim2.new(1, 0, 0, 40)
    title.BackgroundTransparency = 1
    title.Text = "NOVA"
    title.TextColor3 = Color3.fromRGB(255, 60, 60)
    title.Font = Enum.Font.GothamBold
    title.TextSize = 22
    title.Parent = self.Frame

    local subtitle = keep(Instance.new("TextLabel"))
    subtitle.Position = UDim2.fromOffset(0, 38)
    subtitle.Size = UDim2.new(1, 0, 0, 16)
    subtitle.BackgroundTransparency = 1
    subtitle.Text = "Aimbot · Aimlock · Triggerbot · ESP"
    subtitle.TextColor3 = Color3.fromRGB(150, 150, 150)
    subtitle.Font = Enum.Font.Gotham
    subtitle.TextSize = 10
    subtitle.Parent = self.Frame

    local scroll = keep(Instance.new("ScrollingFrame"))
    scroll.Position = UDim2.fromOffset(10, 60)
    scroll.Size = UDim2.new(1, -20, 1, -70)
    scroll.BackgroundTransparency = 1
    scroll.BorderSizePixel = 0
    scroll.ScrollBarThickness = 3
    scroll.ScrollBarImageColor3 = Color3.fromRGB(255, 60, 60)
    scroll.CanvasSize = UDim2.new()
    scroll.AutomaticCanvasSize = Enum.AutomaticSize.Y
    scroll.Parent = self.Frame

    local layout = keep(Instance.new("UIListLayout"))
    layout.Padding = UDim.new(0, 6)
    layout.SortOrder = Enum.SortOrder.LayoutOrder
    layout.Parent = scroll

    self.Scroll = scroll
    self.Layout = layout

    self:_buildContent()
    self:_makeDraggable(title)
    return self
end

function Menu:_addToggle(parent, name, get, set)
    local btn = keep(Instance.new("TextButton"))
    btn.Size = UDim2.new(1, 0, 0, 28)
    btn.BackgroundColor3 = Color3.fromRGB(25, 25, 30)
    btn.BorderSizePixel = 0
    btn.Text = ""
    btn.AutoButtonColor = false
    btn.Parent = parent

    local c = keep(Instance.new("UICorner"))
    c.CornerRadius = UDim.new(0, 5)
    c.Parent = btn

    local lbl = keep(Instance.new("TextLabel"))
    lbl.Size = UDim2.new(1, -50, 1, 0)
    lbl.Position = UDim2.fromOffset(12, 0)
    lbl.BackgroundTransparency = 1
    lbl.Text = name
    lbl.TextColor3 = Color3.fromRGB(220, 220, 220)
    lbl.Font = Enum.Font.Gotham
    lbl.TextSize = 13
    lbl.TextXAlignment = Enum.TextXAlignment.Left
    lbl.Parent = btn

    local dot = keep(Instance.new("Frame"))
    dot.AnchorPoint = Vector2.new(1, 0.5)
    dot.Position = UDim2.new(1, -10, 0.5, 0)
    dot.Size = UDim2.fromOffset(30, 16)
    dot.BackgroundColor3 = Color3.fromRGB(40, 40, 45)
    dot.BorderSizePixel = 0
    dot.Parent = btn

    local dc = keep(Instance.new("UICorner"))
    dc.CornerRadius = UDim.new(1, 0)
    dc.Parent = dot

    local knob = keep(Instance.new("Frame"))
    knob.Size = UDim2.fromOffset(12, 12)
    knob.Position = UDim2.fromOffset(2, 2)
    knob.BackgroundColor3 = Color3.fromRGB(120, 120, 120)
    knob.BorderSizePixel = 0
    knob.Parent = dot

    local kc = keep(Instance.new("UICorner"))
    kc.CornerRadius = UDim.new(1, 0)
    kc.Parent = knob

    local function refresh()
        local on = get()
        dot.BackgroundColor3 = on and Color3.fromRGB(255, 60, 60) or Color3.fromRGB(40, 40, 45)
        knob.BackgroundColor3 = on and Color3.fromRGB(255, 255, 255) or Color3.fromRGB(120, 120, 120)
        TweenService:Create(knob, TweenInfo.new(0.15), {
            Position = on and UDim2.fromOffset(16, 2) or UDim2.fromOffset(2, 2),
        }):Play()
    end
    refresh()

    btn.MouseButton1Click:Connect(function()
        set(not get())
        refresh()
    end)

    return { refresh = refresh }
end

function Menu:_addSlider(parent, name, min, max, step, get, set)
    local frame = keep(Instance.new("Frame"))
    frame.Size = UDim2.new(1, 0, 0, 42)
    frame.BackgroundColor3 = Color3.fromRGB(25, 25, 30)
    frame.BorderSizePixel = 0
    frame.Parent = parent

    local c = keep(Instance.new("UICorner"))
    c.CornerRadius = UDim.new(0, 5)
    c.Parent = frame

    local lbl = keep(Instance.new("TextLabel"))
    lbl.Size = UDim2.new(1, -20, 0, 18)
    lbl.Position = UDim2.fromOffset(10, 2)
    lbl.BackgroundTransparency = 1
    lbl.Text = name .. ": " .. tostring(get())
    lbl.TextColor3 = Color3.fromRGB(220, 220, 220)
    lbl.Font = Enum.Font.Gotham
    lbl.TextSize = 12
    lbl.TextXAlignment = Enum.TextXAlignment.Left
    lbl.Parent = frame

    local bar = keep(Instance.new("TextButton"))
    bar.Position = UDim2.fromOffset(10, 26)
    bar.Size = UDim2.new(1, -20, 0, 8)
    bar.BackgroundColor3 = Color3.fromRGB(40, 40, 45)
    bar.BorderSizePixel = 0
    bar.Text = ""
    bar.AutoButtonColor = false
    bar.Parent = frame

    local bc = keep(Instance.new("UICorner"))
    bc.CornerRadius = UDim.new(1, 0)
    bc.Parent = bar

    local fill = keep(Instance.new("Frame"))
    fill.Size = UDim2.new((get() - min) / (max - min), 0, 1, 0)
    fill.BackgroundColor3 = Color3.fromRGB(255, 60, 60)
    fill.BorderSizePixel = 0
    fill.Parent = bar

    local fc = keep(Instance.new("UICorner"))
    fc.CornerRadius = UDim.new(1, 0)
    fc.Parent = fill

    local dragging = false

    local function update(x)
        local rel = math.clamp((x - bar.AbsolutePosition.X) / bar.AbsoluteSize.X, 0, 1)
        local val = min + rel * (max - min)
        val = math.floor(val / step + 0.5) * step
        val = math.clamp(val, min, max)
        fill.Size = UDim2.new((val - min) / (max - min), 0, 1, 0)
        lbl.Text = name .. ": " .. (step < 1 and string.format("%.2f", val) or tostring(math.floor(val)))
        set(val)
    end

    bar.InputBegan:Connect(function(input)
        if input.UserInputType == Enum.UserInputType.MouseButton1 then
            dragging = true
            update(input.Position.X)
        end
    end)
    track(UserInputService.InputEnded:Connect(function(input)
        if input.UserInputType == Enum.UserInputType.MouseButton1 then dragging = false end
    end))
    track(UserInputService.InputChanged:Connect(function(input)
        if dragging and input.UserInputType == Enum.UserInputType.MouseMovement then
            update(input.Position.X)
        end
    end))

    return { setText = function(t) lbl.Text = t end }
end

function Menu:_section(parent, name)
    local lbl = keep(Instance.new("TextLabel"))
    lbl.Size = UDim2.new(1, 0, 0, 22)
    lbl.BackgroundTransparency = 1
    lbl.Text = name
    lbl.TextColor3 = Color3.fromRGB(255, 60, 60)
    lbl.Font = Enum.Font.GothamBold
    lbl.TextSize = 13
    lbl.TextXAlignment = Enum.TextXAlignment.Left
    lbl.Parent = parent
    return lbl
end

function Menu:_buildContent()
    local s = self.Scroll
    local S = Nova.Settings

    -- AIMBOT
    self:_section(s, "AIMBOT")
    self:_addToggle(s, "Enable Aimbot",
        function() return S.Aimbot.Enabled end,
        function(v) S.Aimbot.Enabled = v updateFOVCircle() end)
    self:_addSlider(s, "FOV",
        10, 1000, S.Aimbot.FOVStep or 10,
        function() return S.Aimbot.FOV end,
        function(v) S.Aimbot.FOV = v updateFOVCircle() end)
    self:_addSlider(s, "Smoothing",
        0, 1, 0.01,
        function() return S.Aimbot.Smoothing end,
        function(v) S.Aimbot.Smoothing = v end)
    self:_addSlider(s, "Max Distance",
        100, 5000, 100,
        function() return S.Aimbot.MaxDistance end,
        function(v) S.Aimbot.MaxDistance = v end)

    -- AIMLOCK
    self:_section(s, "AIMLOCK")
    self:_addToggle(s, "Enable Aimlock",
        function() return S.Aimlock.Enabled end,
        function(v) S.Aimlock.Enabled = v Aim.Target = nil updateFOVCircle() end)
    self:_addSlider(s, "Lock Smoothing",
        0, 1, 0.01,
        function() return S.Aimlock.Smoothing end,
        function(v) S.Aimlock.Smoothing = v end)

    -- TRIGGERBOT
    self:_section(s, "TRIGGERBOT")
    self:_addToggle(s, "Enable Triggerbot",
        function() return S.Triggerbot.Enabled end,
        function(v) S.Triggerbot.Enabled = v end)
    self:_addSlider(s, "Delay (s)",
        0.01, 0.5, 0.01,
        function() return S.Triggerbot.Delay end,
        function(v) S.Triggerbot.Delay = v end)

    -- ESP
    self:_section(s, "ESP")
    self:_addToggle(s, "Enable ESP",
        function() return S.ESP.Enabled end,
        function(v) S.ESP.Enabled = v end)
    self:_addToggle(s, "Show Team",
        function() return S.ESP.ShowTeam end,
        function(v) S.ESP.ShowTeam = v end)
    self:_addSlider(s, "Max Distance",
        50, 5000, 50,
        function() return S.ESP.MaxDistance end,
        function(v) S.ESP.MaxDistance = v end)
end

function Menu:_makeDraggable(dragFrame)
    local dragging = false
    local dragInput, dragStart, startPos
    dragFrame.InputBegan:Connect(function(input)
        if input.UserInputType == Enum.UserInputType.MouseButton1 then
            dragging = true
            dragInput = input
            dragStart = input.Position
            startPos = self.Frame.Position
        end
    end)
    dragFrame.InputChanged:Connect(function(input)
        if dragging and input == dragInput then
            local delta = input.Position - dragStart
            self.Frame.Position = UDim2.fromOffset(startPos.X.Offset + delta.X, startPos.Y.Offset + delta.Y)
        end
    end)
    track(UserInputService.InputEnded:Connect(function(input)
        if input.UserInputType == Enum.UserInputType.MouseButton1 then
            dragging = false
            dragInput = nil
        end
    end))
end

function Menu:Toggle()
    self.Visible = not self.Visible
    self.Gui.Enabled = self.Visible
end

-- ═══════════════════════════════════════════════════════════════
--  입력 처리
-- ═══════════════════════════════════════════════════════════════
track(UserInputService.InputBegan:Connect(function(input, gp)
    if gp then return end

    if input.UserInputType == Enum.UserInputType.MouseButton2 then
        Aim.Aiming = true
    end

    if input.KeyCode == Nova.Settings.MenuKey then
        if Nova.UI then Nova.UI:Toggle() end
    end
    if input.KeyCode == Enum.KeyCode.F1 then
        Nova.Settings.ESP.Enabled = not Nova.Settings.ESP.Enabled
        notify("ESP: " .. tostring(Nova.Settings.ESP.Enabled))
    end
    if input.KeyCode == Enum.KeyCode.F2 then
        Nova.Settings.Aimbot.Enabled = not Nova.Settings.Aimbot.Enabled
        updateFOVCircle()
        notify("Aimbot: " .. tostring(Nova.Settings.Aimbot.Enabled))
    end
    if input.KeyCode == Enum.KeyCode.F4 then
        Nova.Settings.Aimlock.Enabled = not Nova.Settings.Aimlock.Enabled
        Aim.Target = nil
        updateFOVCircle()
        notify("Aimlock: " .. tostring(Nova.Settings.Aimlock.Enabled))
    end
    if input.KeyCode == Enum.KeyCode.F5 then
        Nova.Settings.Triggerbot.Enabled = not Nova.Settings.Triggerbot.Enabled
        notify("Triggerbot: " .. tostring(Nova.Settings.Triggerbot.Enabled))
    end
    if input.KeyCode == Enum.KeyCode.End then        Nova.Unload()
    end
end))

track(UserInputService.InputEnded:Connect(function(input)
    if input.UserInputType == Enum.UserInputType.MouseButton2 then
        Aim.Aiming = false
    end
end))

-- ═══════════════════════════════════════════════════════════════
--  카메라 재바인딩
-- ═══════════════════════════════════════════════════════════════
track(Workspace:GetPropertyChangedSignal("CurrentCamera"):Connect(function()
    Camera = Workspace.CurrentCamera
end))

-- ═══════════════════════════════════════════════════════════════
--  메인 루프
-- ═══════════════════════════════════════════════════════════════
track(RunService.RenderStepped:Connect(function(dt)
    if Nova.Destroyed then return end
    updateFOVCircle()
    updateAimbot(dt)
    updateAimlock(dt)
end))

track(RunService.Heartbeat:Connect(function()
    if Nova.Destroyed then return end
    updateTriggerbot()
end))

-- ═══════════════════════════════════════════════════════════════
--  초기화
-- ═══════════════════════════════════════════════════════════════
createFOVCircle()
updateFOVCircle()

Nova.UI = Menu.new()

-- ═══════════════════════════════════════════════════════════════
--  Unload
-- ═══════════════════════════════════════════════════════════════
function Nova.Unload()
    if Nova.Destroyed then return end
    Nova.Destroyed = true

    for _, c in ipairs(Nova.Connections) do
        pcall(function() c:Disconnect() end)
    end
    for _, i in ipairs(Nova.Instances) do
        pcall(function() i:Destroy() end)
    end

    if getgenv().Nova == Nova then
        getgenv().Nova = nil
    end
    print("[Nova] Unloaded.")
end

-- ═══════════════════════════════════════════════════════════════
--  Ready
-- ═══════════════════════════════════════════════════════════════
notify("Nova loaded successfully!")
notify("F4: Aimlock  |  F5: Triggerbot")
